<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Fight;
use App\Support\CsvExport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomeReportController extends Controller
{
    /**
     * Per-fight house income: what was staked minus what went back out,
     * for every settled (declared or cancelled) fight. A voided ticket
     * never counts either way — the cash it held was handed straight back
     * at the counter. A 'refunded' bet (fight cancelled, or meron/wala
     * refunded on a declared draw) has no `payout` column set, so it's
     * treated as a full refund explicitly rather than via COALESCE — that
     * nets to zero income, same as any other break-even bet.
     */
    public function index(Request $request): View|Response
    {
        [$eventId, $from, $to] = $this->filters($request);

        $fights = $this->filteredQuery($eventId, $from, $to)
            ->with('event:id,name')
            ->select('fights.*', 'totals.staked', 'totals.paid_out')
            ->orderByDesc('fights.declared_at')
            ->paginate(25)
            ->withQueryString();

        $summary = $this->summarize($eventId, $from, $to);

        if ($request->ajax()) {
            return response()->view('superadmin.reports.income._results', compact('fights', 'summary'));
        }

        $events = Event::orderByDesc('id')->get(['id', 'name']);

        return view('superadmin.reports.income.index', [
            'fights' => $fights,
            'events' => $events,
            'summary' => $summary,
            'filters' => [
                'event_id' => $eventId,
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$eventId, $from, $to] = $this->filters($request);

        $fights = $this->filteredQuery($eventId, $from, $to)
            ->with('event:id,name')
            ->select('fights.*', 'totals.staked', 'totals.paid_out')
            ->orderByDesc('fights.declared_at')
            ->cursor()
            ->map(function (Fight $fight) {
                $net = (float) $fight->staked - (float) $fight->paid_out;

                return [
                    $fight->event->name,
                    $fight->fight_number,
                    $fight->status === 'cancelled' ? 'CANCELLED' : ($fight->winner ?? ''),
                    number_format((float) $fight->staked, 2, '.', ''),
                    number_format((float) $fight->paid_out, 2, '.', ''),
                    number_format($net, 2, '.', ''),
                    $fight->declared_at?->format('Y-m-d H:i:s') ?? '',
                ];
            });

        return CsvExport::stream(
            'income-report.csv',
            ['Event', 'Fight #', 'Result', 'Staked', 'Paid out', 'Net income', 'Declared'],
            $fights
        );
    }

    private function filters(Request $request): array
    {
        return [
            $request->integer('event_id') ?: null,
            $request->date('from'),
            $request->date('to'),
        ];
    }

    private function filteredQuery(?int $eventId, $from, $to): Builder
    {
        $totals = DB::table('bets')
            ->select('fight_id')
            ->selectRaw('SUM(amount) as staked')
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN amount ELSE COALESCE(payout, 0) END) as paid_out")
            ->where('status', '!=', 'voided')
            ->groupBy('fight_id');

        return Fight::query()
            ->whereIn('fights.status', ['declared', 'cancelled'])
            ->joinSub($totals, 'totals', 'totals.fight_id', '=', 'fights.id')
            ->when($eventId, fn ($q) => $q->where('fights.event_id', $eventId))
            ->when($from, fn ($q) => $q->whereDate('fights.declared_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fights.declared_at', '<=', $to));
    }

    private function summarize(?int $eventId, $from, $to): object
    {
        $totals = DB::table('bets')
            ->select('fight_id')
            ->selectRaw('SUM(amount) as staked')
            ->selectRaw("SUM(CASE WHEN status = 'refunded' THEN amount ELSE COALESCE(payout, 0) END) as paid_out")
            ->where('status', '!=', 'voided')
            ->groupBy('fight_id');

        // Grand totals across every matching fight, not just the page shown
        // — a fresh copy of the same base query, run without pagination.
        $summaryRow = DB::table('fights')
            ->joinSub($totals, 'totals', 'totals.fight_id', '=', 'fights.id')
            ->whereIn('fights.status', ['declared', 'cancelled'])
            ->when($eventId, fn ($q) => $q->where('fights.event_id', $eventId))
            ->when($from, fn ($q) => $q->whereDate('fights.declared_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('fights.declared_at', '<=', $to))
            ->selectRaw('COALESCE(SUM(totals.staked), 0) as staked')
            ->selectRaw('COALESCE(SUM(totals.paid_out), 0) as paid_out')
            ->first();

        return (object) [
            'staked' => (float) $summaryRow->staked,
            'paid_out' => (float) $summaryRow->paid_out,
        ];
    }
}
