<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Support\CsvExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The event → fight → bet drill-down the client asked for: a rollup per
 * event, the existing per-fight income report as the middle tier (see
 * IncomeReportController — this links into it via ?event_id=), and here
 * the bottom tier — every individual bet on one fight, the actual ledger.
 */
class AccountingController extends Controller
{
    /**
     * Per-event rollup: every fight and bet under it combined into one
     * row per event. Same staked/paid-out accounting rule as the income
     * report (a voided ticket never counts either way; a 'refunded' bet
     * has no payout column set, so it's treated as a full refund
     * explicitly rather than via COALESCE).
     */
    public function events(Request $request): View|Response
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $events = $this->eventsQuery($from, $to)
            ->orderByDesc('events.date')
            ->paginate(25)
            ->withQueryString();

        $summary = $this->eventsSummary($from, $to);

        if ($request->ajax()) {
            return response()->view('superadmin.reports.accounting._events-results', compact('events', 'summary'));
        }

        return view('superadmin.reports.accounting.events', [
            'events' => $events,
            'summary' => $summary,
            'filters' => [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function exportEvents(Request $request): StreamedResponse
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $events = $this->eventsQuery($from, $to)
            ->orderByDesc('events.date')
            ->cursor()
            ->map(function (Event $event) {
                $net = (float) $event->staked - (float) $event->paid_out;

                return [
                    $event->name,
                    $event->date?->format('Y-m-d') ?? '',
                    $event->fight_count,
                    $event->bet_count,
                    number_format((float) $event->staked, 2, '.', ''),
                    number_format((float) $event->paid_out, 2, '.', ''),
                    number_format($net, 2, '.', ''),
                ];
            });

        return CsvExport::stream(
            'betting-accounting-events.csv',
            ['Event', 'Date', 'Fights', 'Bets', 'Staked', 'Paid out', 'Net income'],
            $events
        );
    }

    /**
     * The actual ledger — every bet placed on this fight, win or lose,
     * app player or over-the-counter ticket.
     */
    public function fightBets(Fight $fight): View
    {
        $fight->load('event.game');

        $bets = $this->fightBetsQuery($fight)->paginate(50);

        $summary = $this->fightSummary($fight);

        return view('superadmin.reports.accounting.fight', compact('fight', 'bets', 'summary'));
    }

    public function exportFightBets(Fight $fight): StreamedResponse
    {
        $fight->load('event.game');

        $bets = $this->fightBetsQuery($fight)->cursor()
            ->map(function (Bet $bet) use ($fight) {
                $bettor = $bet->isCounterBet()
                    ? 'Ticket '.$bet->ticket_code.($bet->placedByTeller ? ' (by '.$bet->placedByTeller->displayName().')' : '')
                    : ($bet->user?->displayName() ?? '');

                return [
                    $bettor,
                    $fight->event->sideLabel($bet->side),
                    number_format((float) $bet->amount, 2, '.', ''),
                    $bet->status,
                    $bet->payout !== null ? number_format((float) $bet->payout, 2, '.', '') : '',
                    $bet->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return CsvExport::stream(
            "fight-{$fight->fight_number}-bets.csv",
            ['Bettor', 'Side', 'Amount', 'Status', 'Payout', 'Placed'],
            $bets
        );
    }

    private function eventsQuery($from, $to): Builder
    {
        $totals = DB::table('bets')
            ->join('fights', 'fights.id', '=', 'bets.fight_id')
            ->whereIn('fights.status', ['declared', 'cancelled'])
            ->where('bets.status', '!=', 'voided')
            ->when($from, fn ($q) => $q->whereDate('fights.declared_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fights.declared_at', '<=', $to))
            ->groupBy('fights.event_id')
            ->select('fights.event_id')
            ->selectRaw('COUNT(DISTINCT fights.id) as fight_count')
            ->selectRaw('COUNT(bets.id) as bet_count')
            ->selectRaw('SUM(bets.amount) as staked')
            ->selectRaw("SUM(CASE WHEN bets.status = 'refunded' THEN bets.amount ELSE COALESCE(bets.payout, 0) END) as paid_out");

        return Event::query()
            ->joinSub($totals, 'totals', 'totals.event_id', '=', 'events.id')
            ->select('events.*', 'totals.fight_count', 'totals.bet_count', 'totals.staked', 'totals.paid_out');
    }

    private function eventsSummary($from, $to): object
    {
        $totals = DB::table('bets')
            ->join('fights', 'fights.id', '=', 'bets.fight_id')
            ->whereIn('fights.status', ['declared', 'cancelled'])
            ->where('bets.status', '!=', 'voided')
            ->when($from, fn ($q) => $q->whereDate('fights.declared_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fights.declared_at', '<=', $to))
            ->groupBy('fights.event_id')
            ->select('fights.event_id')
            ->selectRaw('COUNT(bets.id) as bet_count')
            ->selectRaw('SUM(bets.amount) as staked')
            ->selectRaw("SUM(CASE WHEN bets.status = 'refunded' THEN bets.amount ELSE COALESCE(bets.payout, 0) END) as paid_out");

        $summaryRow = DB::table('events')
            ->joinSub($totals, 'totals', 'totals.event_id', '=', 'events.id')
            ->selectRaw('COALESCE(SUM(totals.bet_count), 0) as bet_count')
            ->selectRaw('COALESCE(SUM(totals.staked), 0) as staked')
            ->selectRaw('COALESCE(SUM(totals.paid_out), 0) as paid_out')
            ->first();

        return (object) [
            'bet_count' => (int) $summaryRow->bet_count,
            'staked' => (float) $summaryRow->staked,
            'paid_out' => (float) $summaryRow->paid_out,
        ];
    }

    private function fightBetsQuery(Fight $fight): Builder
    {
        return Bet::where('fight_id', $fight->id)
            ->with(['user:id,name,username', 'placedByTeller:id,name,username', 'redeemedByTeller:id,name,username'])
            ->orderBy('created_at');
    }

    private function fightSummary(Fight $fight): object
    {
        $summaryRow = Bet::where('fight_id', $fight->id)
            ->where('status', '!=', 'voided')
            ->selectRaw('COUNT(*) as bet_count')
            ->selectRaw('SUM(amount) as staked')
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN amount ELSE COALESCE(payout, 0) END) as paid_out")
            ->first();

        return (object) [
            'bet_count' => (int) $summaryRow->bet_count,
            'staked' => (float) $summaryRow->staked,
            'paid_out' => (float) $summaryRow->paid_out,
        ];
    }
}
