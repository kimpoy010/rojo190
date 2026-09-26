<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CsvExport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash flow specific to over-the-counter ticket betting — separate from
 * the wallet deposit/withdrawal cash flow tellers also handle (see
 * TellerShift::totals(), which combines both). This isolates just the
 * ticket side per teller: what they took in writing tickets, what they
 * paid out redeeming winners, and how many they voided.
 *
 * Follows the exact same accounting convention as TellerShift::totals():
 * a voided ticket's stake is excluded entirely from "collected" (not
 * counted as cash-in and then separately refunded), since a void hands
 * that cash straight back and never really left the drawer.
 */
class TellerCashFlowReportController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $rows = $this->rows($from, $to);

        $summary = (object) [
            'tickets_written' => $rows->sum('tickets_written'),
            'stakes_collected' => $rows->sum('stakes_collected'),
            'tickets_redeemed' => $rows->sum('tickets_redeemed'),
            'payouts_paid' => $rows->sum('payouts_paid'),
            'tickets_voided' => $rows->sum('tickets_voided'),
            'net_cash_flow' => $rows->sum('net_cash_flow'),
        ];

        return view('superadmin.reports.teller-cash-flow.index', [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $rows = $this->rows($from, $to)->map(fn ($row) => [
            $row->teller->displayName(),
            $row->tickets_written,
            number_format($row->stakes_collected, 2, '.', ''),
            $row->tickets_redeemed,
            number_format($row->payouts_paid, 2, '.', ''),
            $row->tickets_voided,
            number_format($row->net_cash_flow, 2, '.', ''),
        ]);

        return CsvExport::stream(
            'teller-cash-flow.csv',
            ['Teller', 'Tickets written', 'Stakes collected', 'Tickets redeemed', 'Payouts paid', 'Tickets voided', 'Net cash flow'],
            $rows
        );
    }

    private function rows($from, $to): Collection
    {
        $written = DB::table('bets')
            ->whereNull('user_id')
            ->where('status', '!=', 'voided')
            ->whereNotNull('placed_by_teller_id')
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('placed_by_teller_id')
            ->select('placed_by_teller_id as teller_id')
            ->selectRaw('COUNT(*) as tickets_written')
            ->selectRaw('SUM(amount) as stakes_collected')
            ->get()
            ->keyBy('teller_id');

        $redeemed = DB::table('bets')
            ->whereNull('user_id')
            ->whereNotNull('redeemed_at')
            ->whereNotNull('redeemed_by_teller_id')
            ->when($from, fn ($q) => $q->whereDate('redeemed_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('redeemed_at', '<=', $to))
            ->groupBy('redeemed_by_teller_id')
            ->select('redeemed_by_teller_id as teller_id')
            ->selectRaw('COUNT(*) as tickets_redeemed')
            ->selectRaw('SUM(payout) as payouts_paid')
            ->get()
            ->keyBy('teller_id');

        $voided = DB::table('bets')
            ->whereNull('user_id')
            ->where('status', 'voided')
            ->whereNotNull('voided_by_teller_id')
            ->when($from, fn ($q) => $q->whereDate('voided_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('voided_at', '<=', $to))
            ->groupBy('voided_by_teller_id')
            ->select('voided_by_teller_id as teller_id')
            ->selectRaw('COUNT(*) as tickets_voided')
            ->get()
            ->keyBy('teller_id');

        $tellerIds = collect()
            ->merge($written->keys())
            ->merge($redeemed->keys())
            ->merge($voided->keys())
            ->unique();

        return User::whereIn('id', $tellerIds)
            ->orderBy('name')
            ->get(['id', 'name', 'username'])
            ->map(function (User $teller) use ($written, $redeemed, $voided) {
                $w = $written->get($teller->id);
                $r = $redeemed->get($teller->id);
                $v = $voided->get($teller->id);

                $stakesCollected = (float) ($w->stakes_collected ?? 0);
                $payoutsPaid = (float) ($r->payouts_paid ?? 0);

                return (object) [
                    'teller' => $teller,
                    'tickets_written' => (int) ($w->tickets_written ?? 0),
                    'stakes_collected' => $stakesCollected,
                    'tickets_redeemed' => (int) ($r->tickets_redeemed ?? 0),
                    'payouts_paid' => $payoutsPaid,
                    'tickets_voided' => (int) ($v->tickets_voided ?? 0),
                    'net_cash_flow' => $stakesCollected - $payoutsPaid,
                ];
            });
    }
}
