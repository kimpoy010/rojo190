<?php

namespace App\Http\Controllers\Declarator;

use App\Events\FightStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Fight;
use App\Services\CombinedBettingService;
use App\Services\FightService;
use App\Support\AuditLogger;
use App\Support\Broadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * declare/cancel/redeclare for a CombinedSabong fight — everything else in
 * a fight's lifecycle (open/last-call/close/toggle-draw/cockpit/fight-
 * number/the bets JSON feed) is game-agnostic and stays on the existing
 * declarator.fights.* routes/controller, shared as-is with Pool Sabong.
 * Only settlement differs, which is why only these three actions get
 * their own controller here, calling CombinedBettingService instead of
 * BettingService.
 */
class CombinedFightController extends Controller
{
    public function __construct(
        private FightService $fightService,
        private CombinedBettingService $bettingService,
    ) {}

    public function declare(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $request->validate(['winner' => 'required|in:meron,wala,draw']);

        try {
            DB::transaction(function () use ($fight, $request) {
                $declaredFight = $this->fightService->declareWinner($fight, $request->winner);
                $this->bettingService->settleBets($declaredFight, $request->winner);
                DB::afterCommit(fn () => Broadcaster::send(new FightStatusUpdated($declaredFight->fresh())));
            });
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Winner declared: :winner.', ['winner' => $request->winner]));
    }

    public function cancel(Fight $fight): JsonResponse|RedirectResponse
    {
        if (! in_array($fight->status, Fight::IN_PLAY_STATUSES, true)) {
            return $this->fail($fight, __('Only pending, open, or closed fights can be cancelled. Current status: :status', ['status' => $fight->status]));
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

            $this->fightService->createNextFight($fight);
        });

        Broadcaster::send(new FightStatusUpdated($fight->fresh()));

        return $this->ok($fight, __('Fight #:number cancelled. All bets refunded.', ['number' => $fight->fight_number]));
    }

    public function redeclare(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $request->validate(['winner' => ['required', Rule::in(['meron', 'wala', 'draw', 'cancelled'])]]);

        if (! in_array($fight->status, ['declared', 'cancelled'], true)) {
            return $this->fail($fight, __('Only declared or cancelled fights can be re-declared. Current status: :status', ['status' => $fight->status]));
        }

        $oldStatus = $fight->status;
        $oldWinner = $fight->winner;
        $newWinner = $request->winner;

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

        Broadcaster::send(new FightStatusUpdated($fight->fresh()));

        return $this->ok($fight, __('Fight #:number re-declared: :winner.', ['number' => $fight->fight_number, 'winner' => $newWinner]));
    }

    private function ok(Fight $fight, string $message): JsonResponse|RedirectResponse
    {
        if (request()->ajax() || request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route('declarator.events.show', $fight->event)->with('success', $message);
    }

    private function fail(Fight $fight, string $message): JsonResponse|RedirectResponse
    {
        if (request()->ajax() || request()->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->route('declarator.events.show', $fight->event)->with('error', $message);
    }
}
