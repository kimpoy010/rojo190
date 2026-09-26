<?php

namespace App\Http\Controllers\Declarator;

use App\Events\FightStatusUpdated;
use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Cockpit;
use App\Models\Fight;
use App\Services\BettingService;
use App\Services\FightService;
use App\Support\Broadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FightController extends Controller
{
    public function __construct(
        private FightService $fightService,
        private BettingService $bettingService,
    ) {}

    public function open(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        // Required only once at least one cockpit exists — keeps a fresh
        // install (nobody's set any up yet) from being blocked before the
        // superadmin has had a chance to add one.
        $request->validate([
            'cockpit_id' => [Cockpit::exists() ? 'required' : 'nullable', 'integer', Rule::exists('cockpits', 'id')],
        ]);

        try {
            $this->fightService->openBets($fight, $request->integer('cockpit_id') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Bets are now open.'));
    }

    public function lastCall(Fight $fight): JsonResponse|RedirectResponse
    {
        try {
            DB::transaction(function () use ($fight) {
                $updated = $this->fightService->lastCall($fight);
                DB::afterCommit(fn () => Broadcaster::send(new FightStatusUpdated($updated)));
            });
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Last call — closing very soon.'));
    }

    public function close(Fight $fight): JsonResponse|RedirectResponse
    {
        try {
            DB::transaction(function () use ($fight) {
                $closedFight = $this->fightService->closeBets($fight);
                DB::afterCommit(fn () => Broadcaster::send(new FightStatusUpdated($closedFight)));
            });
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Bets closed.'));
    }

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

    public function toggleDraw(Fight $fight): JsonResponse|RedirectResponse
    {
        try {
            $updated = $this->fightService->toggleDraw($fight);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, $updated->draw_enabled ? __('Draw enabled.') : __('Draw disabled.'));
    }

    public function cancel(Fight $fight): JsonResponse|RedirectResponse
    {
        try {
            $this->fightService->cancelFight($fight);
            Broadcaster::send(new FightStatusUpdated($fight->fresh()));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Fight #:number cancelled. All bets refunded.', ['number' => $fight->fight_number]));
    }

    public function redeclare(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        // draw_enabled only ever gated whether players could bet on a draw —
        // re-declaring to fix a mistake must not be blocked by it either.
        $request->validate(['winner' => ['required', Rule::in(['meron', 'wala', 'draw', 'cancelled'])]]);

        try {
            $this->fightService->redeclareFight($fight, $request->winner);
            Broadcaster::send(new FightStatusUpdated($fight->fresh()));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Fight #:number re-declared: :winner.', ['number' => $fight->fight_number, 'winner' => $request->winner]));
    }

    public function bets(Fight $fight): JsonResponse
    {
        $bets = Bet::where('fight_id', $fight->id)
            ->with('user:id,name,username')
            ->orderByDesc('amount')
            ->limit(100)
            ->get(['id', 'user_id', 'side', 'amount', 'status', 'created_at']);

        $poolRaw = Bet::inPool()->where('fight_id', $fight->id)
            ->selectRaw("
                SUM(CASE WHEN side='meron' THEN amount ELSE 0 END) as meron,
                SUM(CASE WHEN side='wala'  THEN amount ELSE 0 END) as wala,
                SUM(CASE WHEN side='draw'  THEN amount ELSE 0 END) as draw
            ")
            ->first();

        return response()->json([
            'bets' => $bets->map(fn ($b) => [
                'name' => $b->user->username ?? $b->user->name,
                // sideLabel(), not the raw 'meron'/'wala' column value —
                // this event's own region-specific labels (e.g. Rojo/Verde
                // for Mexico) rather than always the Philippines defaults.
                'side' => $fight->event->sideLabel($b->side),
                'amount' => number_format((float) $b->amount, 2),
                'time' => $b->created_at->format('H:i:s'),
                'status' => $b->status,
            ]),
            'pool' => [
                'meron' => number_format((float) $poolRaw->meron, 2),
                'wala' => number_format((float) $poolRaw->wala, 2),
                'draw' => number_format((float) $poolRaw->draw, 2),
            ],
        ]);
    }

    public function updateFightNumber(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $request->validate([
            'fight_number' => [
                'required', 'integer', 'min:1', 'max:9999',
                Rule::unique('fights', 'fight_number')->where('event_id', $fight->event_id)->ignore($fight->id),
            ],
        ]);

        $fight->update(['fight_number' => $request->fight_number]);
        Broadcaster::send(new FightStatusUpdated($fight->fresh()));

        return $this->ok($fight, __('Fight number updated.'));
    }

    /**
     * Reassign a fight already open/closed to a different cockpit — for
     * correcting a mistake (e.g. two parallel fights accidentally left on
     * the same ring) without having to cancel and restart either one.
     */
    public function updateCockpit(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $request->validate([
            'cockpit_id' => ['nullable', 'integer', Rule::exists('cockpits', 'id')],
        ]);

        try {
            $this->fightService->assignCockpit($fight, $request->integer('cockpit_id') ?: null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($fight, $e->getMessage());
        }

        return $this->ok($fight, __('Cockpit updated.'));
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
