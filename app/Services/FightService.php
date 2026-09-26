<?php

namespace App\Services;

use App\Events\FightStatusUpdated;
use App\Models\Event;
use App\Models\Fight;
use App\Support\AuditLogger;
use App\Support\Broadcaster;
use Illuminate\Support\Facades\DB;

class FightService
{
    public function __construct(
        private BettingService $bettingService,
    ) {}

    public function openBets(Fight $fight, ?int $cockpitId = null): Fight
    {
        if ($fight->status !== 'pending') {
            throw new \InvalidArgumentException(__('Only a pending fight can be opened. Current status: :status', ['status' => $fight->status]));
        }

        $cockpitId = $cockpitId ?? $fight->cockpit_id;
        $this->assertCockpitAvailable($fight, $cockpitId);

        $fight->update(['status' => 'open', 'cockpit_id' => $cockpitId]);

        $fight->load('event');
        if ($fight->event->status === 'upcoming') {
            $fight->event->update(['status' => 'live']);
        }

        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.opened',
            description: __('Opened betting on Fight #:number.', ['number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['status' => ['old' => 'pending', 'new' => 'open'], 'cockpit_id' => $cockpitId],
        );

        Broadcaster::send(new FightStatusUpdated($fresh));

        return $fresh;
    }

    /**
     * A "closing very soon" warning stage between open and closed — betting
     * itself is unaffected (see Fight::BETTABLE_STATUSES), this only
     * changes what everyone watching sees.
     */
    public function lastCall(Fight $fight): Fight
    {
        if ($fight->status !== 'open') {
            throw new \InvalidArgumentException(__('Only an open fight can go to last call. Current status: :status', ['status' => $fight->status]));
        }

        $fight->update(['status' => 'last_call']);
        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.last_call',
            description: __('Fight #:number moved to last call.', ['number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['status' => ['old' => 'open', 'new' => 'last_call']],
        );

        return $fresh;
    }

    public function closeBets(Fight $fight): Fight
    {
        // Last call is a required step, not an optional one — enforced
        // here too (not just by the UI hiding "Close bets" until then) so
        // betting can never be cut short without it.
        if ($fight->status !== 'last_call') {
            throw new \InvalidArgumentException(__('Only a fight in last call can be closed. Current status: :status', ['status' => $fight->status]));
        }

        $fight->update(['status' => 'closed']);
        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.closed',
            description: __('Closed betting on Fight #:number.', ['number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['status' => ['old' => 'last_call', 'new' => 'closed']],
        );

        return $fresh;
    }

    /**
     * Reassign a fight's cockpit outside the open/close flow (e.g. fixing a
     * mistaken pick while betting is already underway). Subject to the same
     * availability rule as opening bets.
     */
    public function assignCockpit(Fight $fight, ?int $cockpitId): Fight
    {
        $oldCockpitId = $fight->cockpit_id;
        $this->assertCockpitAvailable($fight, $cockpitId);

        $fight->update(['cockpit_id' => $cockpitId]);

        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.cockpit_reassigned',
            description: __('Reassigned Fight #:number to a different cockpit.', ['number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['cockpit_id' => ['old' => $oldCockpitId, 'new' => $cockpitId]],
        );

        Broadcaster::send(new FightStatusUpdated($fresh));

        return $fresh;
    }

    /**
     * A cockpit can't stream two fights of the SAME event at once — but
     * different events running concurrently are free to use the same
     * cockpit independently of each other (two live cards can each have
     * their own "Ring 1", say). The UI already greys out an in-use
     * cockpit's radio/option within the event being edited (see
     * declarator/events/_fights_panel.blade.php), but that's just a
     * client hint; this is the rule actually enforced. $fight's own
     * current cockpit doesn't count as "in use" against itself.
     */
    private function assertCockpitAvailable(Fight $fight, ?int $cockpitId): void
    {
        if (! $cockpitId) {
            return;
        }

        $heldByAnotherFight = Fight::whereIn('status', Fight::IN_PLAY_STATUSES)
            ->where('cockpit_id', $cockpitId)
            ->where('event_id', $fight->event_id)
            ->where('id', '!=', $fight->id)
            ->exists();

        if ($heldByAnotherFight) {
            throw new \InvalidArgumentException(__('That cockpit is already in use by another fight in this event.'));
        }
    }

    public function declareWinner(Fight $fight, string $winner): Fight
    {
        if ($fight->status !== 'closed') {
            throw new \InvalidArgumentException(__('Only a closed fight can be declared. Current status: :status', ['status' => $fight->status]));
        }

        // draw_enabled only ever gated whether players could bet on a draw
        // (see BettingService::placeBet) — the fight can still genuinely
        // end in one regardless, so declaring it must not be blocked here.
        $validWinners = ['meron', 'wala', 'draw'];

        if (! in_array($winner, $validWinners, true)) {
            throw new \InvalidArgumentException(__("Invalid winner ':winner'. Allowed: :allowed", ['winner' => $winner, 'allowed' => implode(', ', $validWinners)]));
        }

        $fight->update([
            'status' => 'declared',
            'winner' => $winner,
            'declared_at' => now(),
        ]);
        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.declared',
            description: __('Declared :winner as the winner of Fight #:number.', ['winner' => $winner, 'number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['status' => ['old' => 'closed', 'new' => 'declared'], 'winner' => ['old' => null, 'new' => $winner]],
        );

        $this->createNextFight($fight);

        return $fresh;
    }

    public function toggleDraw(Fight $fight): Fight
    {
        if ($fight->status === 'declared') {
            throw new \InvalidArgumentException(__('Cannot change draw setting on a declared fight.'));
        }

        $oldValue = $fight->draw_enabled;
        $fight->update(['draw_enabled' => ! $fight->draw_enabled]);
        $fresh = $fight->fresh();

        AuditLogger::log(
            action: 'fight.draw_toggled',
            description: __('Draw betting :state for Fight #:number.', ['state' => $fresh->draw_enabled ? __('enabled') : __('disabled'), 'number' => $fresh->fight_number]),
            target: $fresh,
            changes: ['draw_enabled' => ['old' => $oldValue, 'new' => $fresh->draw_enabled]],
        );

        return $fresh;
    }

    /**
     * Cancel a pending, open, last-call, or closed fight: refund all bets,
     * mark the fight cancelled, and create the next pending fight.
     */
    public function cancelFight(Fight $fight): void
    {
        if (! in_array($fight->status, Fight::IN_PLAY_STATUSES, true)) {
            throw new \InvalidArgumentException(
                __('Only pending, open, or closed fights can be cancelled. Current status: :status', ['status' => $fight->status])
            );
        }

        $oldStatus = $fight->status;

        DB::transaction(function () use ($fight, $oldStatus) {
            $this->bettingService->refundAll($fight);
            $fight->update(['status' => 'cancelled']);

            AuditLogger::log(
                action: 'fight.cancelled',
                description: __('Cancelled Fight #:number — every bet refunded.', ['number' => $fight->fight_number]),
                target: $fight->fresh(),
                changes: ['status' => ['old' => $oldStatus, 'new' => 'cancelled']],
            );

            $this->createNextFight($fight);
        });
    }

    /**
     * Re-declare a fight that was previously declared or cancelled.
     *
     * Reverses all settled payouts/refunds first (wallets may go negative),
     * then either re-settles with $newWinner or cancels (full refund). Does
     * NOT create a new fight — the next fight already exists from the
     * original declaration/cancellation.
     */
    public function redeclareFight(Fight $fight, string $newWinner): void
    {
        if (! in_array($fight->status, ['declared', 'cancelled'], true)) {
            throw new \InvalidArgumentException(
                __('Only declared or cancelled fights can be re-declared. Current status: :status', ['status' => $fight->status])
            );
        }

        $oldStatus = $fight->status;
        $oldWinner = $fight->winner;

        DB::transaction(function () use ($fight, $newWinner, $oldStatus, $oldWinner) {
            $this->bettingService->reverseSettlement($fight);

            if ($newWinner === 'cancelled') {
                $this->bettingService->refundAll($fight);
                $fight->update(['status' => 'cancelled', 'winner' => null]);
            } else {
                $this->bettingService->settleBets($fight, $newWinner);
                $fight->update([
                    'status' => 'declared',
                    'winner' => $newWinner,
                    'declared_at' => now(),
                ]);
            }

            AuditLogger::log(
                action: 'fight.redeclared',
                description: __('Re-declared Fight #:number: :old → :new.', ['number' => $fight->fight_number, 'old' => $oldWinner ?? $oldStatus, 'new' => $newWinner]),
                target: $fight->fresh(),
                changes: [
                    'status' => ['old' => $oldStatus, 'new' => $newWinner === 'cancelled' ? 'cancelled' : 'declared'],
                    'winner' => ['old' => $oldWinner, 'new' => $newWinner === 'cancelled' ? null : $newWinner],
                ],
            );
        });
    }

    /**
     * Manually start the next fight for an event without waiting for the
     * current one to be declared — for a match that's dragging on (or a
     * declaration backlog) while the crew wants to keep the action moving.
     * Declaring/cancelling the backlogged fight later works exactly as
     * before; it just does so alongside whatever fight was started here in
     * the meantime, instead of blocking it.
     */
    public function startNextFight(Event $event): Fight
    {
        if ($event->status !== 'live') {
            throw new \InvalidArgumentException(__('Only a live event can start a new fight.'));
        }

        $latest = $event->fights()->orderByDesc('fight_number')->first();

        if ($latest && in_array($latest->status, ['pending', 'open', 'last_call'], true)) {
            throw new \InvalidArgumentException(__("Fight #:number hasn't closed betting yet.", ['number' => $latest->fight_number]));
        }

        $fight = Fight::create([
            'event_id' => $event->id,
            'fight_number' => $latest ? $latest->fight_number + 1 : 1,
            'status' => 'pending',
            'draw_enabled' => $event->draw_enabled,
        ]);

        AuditLogger::log(
            action: 'fight.started_manually',
            description: __('Manually started Fight #:number for :event.', ['number' => $fight->fight_number, 'event' => $event->name]),
            target: $fight,
        );

        // Nobody has this fight open in a browser tab yet to subscribe to its
        // own fight.{id} channel, so this only reaches viewers via the
        // event.{id} channel — which is exactly who needs to know: players
        // on another fight of this event (to add it to their fight switcher)
        // and every declarator watching the event console (to render its
        // card). manualStart: true also pops the "a new fight has started"
        // notice on any player still on a closed fight awaiting declaration.
        Broadcaster::send(new FightStatusUpdated($fight, manualStart: true));

        return $fight;
    }

    public function createNextFight(Fight $fight): ?Fight
    {
        if ($fight->event->status !== 'live') {
            return null;
        }

        $next = Fight::firstOrCreate(
            [
                'event_id' => $fight->event_id,
                'fight_number' => $fight->fight_number + 1,
            ],
            [
                'status' => 'pending',
                'draw_enabled' => $fight->event->draw_enabled,
            ]
        );

        if ($next->wasRecentlyCreated) {
            // manualStart defaults to false here — this is the routine next
            // fight after a declare/cancel, not the "jump ahead" action, so
            // it still refreshes tabs/consoles but never pops the "a new
            // fight has started" notice (the player whose fight this was
            // is about to see the result overlay instead).
            Broadcaster::send(new FightStatusUpdated($next));
        }

        return $next;
    }
}
