<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Event;
use App\Support\WalletTransactionEventNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    private const TABS = ['all', 'bets', 'deposits', 'withdrawals'];

    /**
     * Filterable via ?type= on the "All Transactions" tab only —
     * deliberately narrower than every reference_type that exists
     * (draw_payout/commission_transfer never land on a player's own
     * wallet, so offering them in the filter would just be dead options).
     */
    private const FILTERABLE_TYPES = ['bet', 'payout', 'refund', 'deposit', 'withdrawal', 'reversal'];

    /**
     * Which reference_types the Bets/Deposits/Withdrawals tabs each show.
     * Bets reuses WalletTransactionEventNames' own list (every type whose
     * reference_id is a Bet id — the full bet lifecycle, not just
     * placements). Deposits/Withdrawals fold in the admin_topup/
     * admin_withdraw types a superadmin's manual adjustment creates —
     * still money added/removed from this player's own perspective, just
     * through a different door than the Cash In/Out page.
     */
    private const TAB_REFERENCE_TYPES = [
        'bets' => WalletTransactionEventNames::BET_LINKED_REFERENCE_TYPES,
        'deposits' => ['deposit', 'admin_topup'],
        'withdrawals' => ['withdrawal', 'admin_withdraw'],
    ];

    /**
     * The player's wallet transaction history — every credit/debit that has
     * touched their main balance (bets, payouts, refunds, deposits,
     * withdrawals). This is a read-only ledger view; deposits/withdrawals
     * themselves are still initiated from the Cash In/Out page.
     *
     * Four tabs, each with its own filter set (see wallet/index.blade.php):
     * All Transactions (type + date range), Bets (which event), Deposits/
     * Withdrawals (date range and/or exact amount). Tab switching and
     * every filter/pagination change re-fetch through this same action via
     * AJAX — an ajax() request gets just the rows partial back instead of
     * the full page, same pattern as the superadmin wallets page's live
     * search.
     */
    public function index(Request $request): View|Response
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'type' => ['nullable', Rule::in(self::FILTERABLE_TYPES)],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $tab = $data['tab'] ?? 'all';
        $type = $tab === 'all' ? ($data['type'] ?? null) : null;
        $eventId = $tab === 'bets' ? ($data['event_id'] ?? null) : null;
        $amount = in_array($tab, ['deposits', 'withdrawals'], true) ? ($data['amount'] ?? null) : null;
        $dateRangeApplies = in_array($tab, ['all', 'deposits', 'withdrawals'], true);
        $dateFrom = $dateRangeApplies ? ($data['date_from'] ?? null) : null;
        $dateTo = $dateRangeApplies ? ($data['date_to'] ?? null) : null;

        $wallet = auth()->user()->wallet;

        $transactions = $wallet
            ? $wallet->transactions()
                ->when($tab !== 'all', fn ($q) => $q->whereIn('reference_type', self::TAB_REFERENCE_TYPES[$tab]))
                ->when($type, fn ($q) => $q->where('reference_type', $type))
                ->when($amount !== null, fn ($q) => $q->where('amount', $amount))
                ->when($dateFrom, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                ->when($dateTo, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
                ->when($eventId, function ($q) use ($eventId) {
                    $betIds = Bet::whereHas('fight', fn ($fq) => $fq->where('event_id', $eventId))->pluck('id');
                    $q->whereIn('reference_id', $betIds);
                })
                ->orderByDesc('created_at')
                ->paginate(15)
                ->withQueryString()
            : null;

        $eventNamesByBetId = $transactions
            ? WalletTransactionEventNames::forTransactions($transactions->getCollection())
            : collect();

        $betSummaries = $transactions
            ? WalletTransactionEventNames::betSummaries($transactions->getCollection())
            : collect();

        // Only rendered on the Bets tab, but cheap enough (one player's own
        // events) to just always compute.
        $playerEvents = Event::whereHas('fights.bets', fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($request->ajax()) {
            return response()->view('player.partials.wallet-transaction-rows', compact('transactions', 'eventNamesByBetId', 'betSummaries'));
        }

        return view('player.wallet.index', [
            'wallet' => $wallet,
            'transactions' => $transactions,
            'eventNamesByBetId' => $eventNamesByBetId,
            'betSummaries' => $betSummaries,
            'tab' => $tab,
            'type' => $type,
            'eventId' => $eventId,
            'amount' => $amount,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'playerEvents' => $playerEvents,
        ]);
    }
}
