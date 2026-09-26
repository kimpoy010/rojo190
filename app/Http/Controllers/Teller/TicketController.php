<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Services\AdminPinService;
use App\Services\BettingService;
use App\Services\TellerShiftService;
use App\Support\GameTheme;
use App\Support\PoolPayoutCalculator;
use App\Support\ScannedCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "bet writer" flow: a teller takes a cash bet from a walk-up bettor
 * who has no app account, and hands them a printed ticket with a QR code
 * as their claim reference. If it wins, they bring the ticket back to any
 * teller counter to collect in cash.
 */
class TicketController extends Controller
{
    public function __construct(
        private BettingService $bettingService,
        private TellerShiftService $shiftService,
        private AdminPinService $adminPinService,
    ) {}

    public function create(): View
    {
        $event = $this->activeEvent();
        $fight = $event?->fights()->whereIn('status', Fight::BETTABLE_STATUSES)->latest('fight_number')->first();
        $theme = $event?->game?->theme() ?? GameTheme::for(null);
        $history = $event ? $this->historyFor($event) : collect();

        return view('teller.tickets.create', array_merge(
            compact('event', 'fight', 'theme', 'history'),
            $this->poolData($fight)
        ));
    }

    /**
     * Live totals for the open fight so the teller can see how the pools
     * (and payout odds) are shifting while writing tickets — polled from
     * the create view rather than requiring a page reload. Also carries a
     * freshly-rendered history table so a void or a ticket written at
     * another window shows up here without a page reload.
     */
    public function status(): JsonResponse
    {
        $event = $this->activeEvent();
        $fight = $event?->fights()->whereIn('status', Fight::BETTABLE_STATUSES)->latest('fight_number')->first();
        $theme = $event?->game?->theme() ?? GameTheme::for(null);
        $poolData = $this->poolData($fight);

        $historyHtml = view('teller.tickets._history_rows', [
            'history' => $event ? $this->historyFor($event) : collect(),
        ])->render();

        // Rendered fresh every poll but only swapped in by the client when
        // the open fight's identity actually changes (closes, a new one
        // opens, the event ends) — see setupBetPanel()/applyPoolData() in
        // create.blade.php. A same-fight tick only patches pool numbers in
        // place so an in-progress amount a teller is typing survives it.
        $betPanelHtml = view('teller.tickets._bet_panel', array_merge(
            compact('event', 'fight', 'theme'), $poolData
        ))->render();

        return response()->json([
            'open' => (bool) $fight,
            'event_id' => $event?->id,
            'fight_id' => $fight?->id,
            'fight_number' => $fight?->fight_number,
            'historyHtml' => $historyHtml,
            'betPanelHtml' => $betPanelHtml,
        ] + $poolData);
    }

    /**
     * Ticket history for the active event, newest fight first — lets a
     * teller reprint or void a ticket without knowing its code.
     *
     * @return \Illuminate\Support\Collection<int, Bet>
     */
    private function historyFor(Event $event, int $limit = 100)
    {
        return Bet::query()
            ->select('bets.*')
            ->join('fights', 'fights.id', '=', 'bets.fight_id')
            ->where('fights.event_id', $event->id)
            ->whereNotNull('bets.ticket_code')
            ->orderByDesc('fights.fight_number')
            ->orderByDesc('bets.created_at')
            ->with(['fight.event', 'placedByTeller'])
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{meronPool: float, walaPool: float, drawPool: float, payouts: array, drawMultiplier: float}
     */
    private function poolData(?Fight $fight): array
    {
        if (! $fight) {
            return [
                'meronPool' => 0.0, 'walaPool' => 0.0, 'drawPool' => 0.0,
                'payouts' => ['meron' => 0.0, 'wala' => 0.0, 'meron_pct' => 0.0, 'wala_pct' => 0.0], 'drawMultiplier' => 8.00,
            ];
        }

        $game = $fight->event->game;
        $meronPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'meron')->sum('amount');
        $walaPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'wala')->sum('amount');
        $drawPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'draw')->sum('amount');
        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';

        return [
            'meronPool' => $meronPool,
            'walaPool' => $walaPool,
            'drawPool' => $drawPool,
            'payouts' => PoolPayoutCalculator::calculate($meronPool, $walaPool, $plasada, $plasadaMode),
            'drawMultiplier' => $game ? (float) $game->draw_multiplier : 8.00,
        ];
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'side' => 'required|in:meron,wala,draw',
            'amount' => 'required|numeric|min:1',
        ]);

        $wantsJson = $request->wantsJson();

        $shift = $this->shiftService->currentShift(auth()->user());
        if (! $shift) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'redirect' => route('teller.shift.start')], 422);
            }

            return redirect()->route('teller.shift.start')->with('error', __('Start your shift before writing tickets.'));
        }

        // The event is never trusted from the request — same principle as
        // RFID reader role resolution: the teller writes against whichever
        // event the declarator has live right now, resolved server-side.
        $event = $this->activeEvent();

        if (! $event) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => __('No active event right now.')], 422);
            }

            return redirect()->route('teller.tickets.create')->with('error', __('No active event right now.'))->withInput();
        }

        $fight = $event->fights()->whereIn('status', Fight::BETTABLE_STATUSES)->latest('fight_number')->first();

        if (! $fight) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => __('Betting is not open for that event right now.')], 422);
            }

            return redirect()->route('teller.tickets.create')->with('error', __('Betting is not open for that event right now.'))->withInput();
        }

        try {
            $bet = $this->bettingService->placeCounterBet(auth()->user(), $shift, $fight, $data['side'], (float) $data['amount']);
        } catch (\InvalidArgumentException $e) {
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('teller.tickets.create')->with('error', $e->getMessage())->withInput();
        }

        if ($wantsJson) {
            $bet->load('fight.event.game', 'placedByTeller');

            return response()->json([
                'success' => true,
                'ticket_code' => $bet->ticket_code,
                'receipt_html' => view('teller.tickets._printable', ['bet' => $bet])->render(),
            ] + $this->poolData($fight->fresh()));
        }

        return redirect()->route('teller.tickets.receipt', $bet);
    }

    /**
     * The single event the declarator currently has live — the bet-writer
     * flow has no per-teller event picker, it always writes against this one.
     */
    private function activeEvent(): ?Event
    {
        return Event::where('status', 'live')->latest('id')->first();
    }

    public function receipt(Request $request, Bet $bet): View|JsonResponse
    {
        abort_unless($bet->isCounterBet(), 404);

        $bet->load('fight.event');

        // The history table's "Print" button fetches this same receipt via
        // AJAX (a reprint), rather than navigating away to the full page.
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'receipt_html' => view('teller.tickets._printable', ['bet' => $bet])->render(),
            ]);
        }

        return view('teller.tickets.receipt', compact('bet'));
    }

    /**
     * Pull a ticket out of the pool. Requires a superadmin's PIN — the
     * teller can't approve their own void, they just relay the PIN an
     * admin types in for them.
     */
    public function void(Request $request, Bet $bet): JsonResponse
    {
        abort_unless($bet->isCounterBet(), 404);

        $data = $request->validate(['pin' => 'required|string']);

        $shift = $this->shiftService->currentShift(auth()->user());
        if (! $shift) {
            return response()->json(['success' => false, 'message' => __('Start your shift before voiding a ticket.')], 422);
        }

        $admin = $this->adminPinService->findApprover($data['pin']);
        if (! $admin) {
            return response()->json(['success' => false, 'message' => __('Incorrect admin PIN.')], 422);
        }

        try {
            $this->bettingService->voidBet($bet, auth()->user(), $admin);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('Ticket voided — return :amount in cash to the bettor.', ['amount' => '$'.number_format((float) $bet->amount, 2)]),
        ]);
    }

    /**
     * Manual fallback for when a camera isn't available — the teller
     * types/pastes the code printed on the ticket.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        $raw = ScannedCode::extract($data['code']);

        // Ticket codes are 10-digit zero-padded numbers — a teller typing
        // it back in won't necessarily include the leading zeros, so pad
        // pure-numeric input before matching. A non-numeric code (from a
        // ticket written before this format existed) matches as-is.
        $code = ctype_digit($raw) ? str_pad($raw, 10, '0', STR_PAD_LEFT) : strtoupper($raw);

        $bet = Bet::where('ticket_code', $code)->first();

        if (! $bet) {
            return redirect()->route('teller.tickets.create')->with('error', __('No ticket found for that code.'));
        }

        return redirect()->route('teller.tickets.show', $bet);
    }

    public function show(Bet $bet): View
    {
        abort_unless($bet->isCounterBet(), 404);

        $bet->load('fight.event', 'placedByTeller', 'redeemedByTeller');

        return view('teller.tickets.show', compact('bet'));
    }

    public function redeem(Bet $bet): RedirectResponse
    {
        abort_unless($bet->isCounterBet(), 404);

        $shift = $this->shiftService->currentShift(auth()->user());
        if (! $shift) {
            return redirect()->route('teller.shift.start')->with('error', __('Start your shift before paying out a ticket.'));
        }

        try {
            $this->bettingService->redeemTicket($bet->ticket_code, auth()->user(), $shift);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.tickets.show', $bet)->with('error', $e->getMessage());
        }

        return redirect()->route('teller.tickets.show', $bet)->with('success', __('Paid out — ticket redeemed.'));
    }
}
