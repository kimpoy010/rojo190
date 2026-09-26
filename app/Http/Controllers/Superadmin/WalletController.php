<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Event;
use App\Models\User;
use App\Services\WalletService;
use App\Support\WalletTransactionEventNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    /**
     * Which reference_types each transactions-page tab shows. 'bets' reuses
     * WalletTransactionEventNames' own list (every type whose reference_id
     * is a Bet id) since that's exactly "the bet lifecycle." Deposits and
     * withdrawals also fold in the admin_topup/admin_withdraw types credit()
     * and debit() below create — a manual admin adjustment is still money
     * added or removed from the player's perspective, same as a player-
     * initiated cash deposit/withdrawal, just through a different door.
     */
    private const TAB_REFERENCE_TYPES = [
        'bets' => WalletTransactionEventNames::BET_LINKED_REFERENCE_TYPES,
        'deposits' => ['deposit', 'admin_topup'],
        'withdrawals' => ['withdrawal', 'admin_withdraw'],
    ];

    public function __construct(private WalletService $walletService) {}

    /**
     * The row list + pagination (see wallets/_rows.blade.php) is also
     * fetched on its own, as plain HTML, by the search box's live-search
     * script and by its own pagination links — hence returning that
     * partial directly whenever the request is flagged as ajax, instead
     * of the full page.
     */
    public function index(Request $request): View|Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::with('wallet')->role('player')
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        if ($request->ajax()) {
            return response()->view('superadmin.wallets._rows', compact('users'));
        }

        return view('superadmin.wallets.index', compact('users', 'q'));
    }

    /**
     * A specific player's full wallet ledger — tabbed (all/bets/deposits/
     * withdrawals), filterable by date range, and (bets tab only) by which
     * event a bet belongs to. Plain GET + Laravel's own paginator, same
     * convention as the accounting report pages, rather than the AJAX
     * pattern the player-facing wallet list uses — this is a desktop admin
     * table, not a mobile card list.
     */
    public function transactions(Request $request, User $user): View|Response
    {
        abort_if(! $user->hasRole('player'), 422, 'Target user is not a player.');

        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'bets', 'deposits', 'withdrawals'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $tab = $data['tab'] ?? 'all';
        $eventId = $tab === 'bets' ? ($data['event_id'] ?? null) : null;

        $wallet = $user->wallet;

        $transactions = $wallet
            ? $wallet->transactions()
                ->when($tab !== 'all', fn ($q) => $q->whereIn('reference_type', self::TAB_REFERENCE_TYPES[$tab]))
                ->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                ->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
                ->when($eventId, function ($q) use ($eventId) {
                    $betIds = Bet::whereHas('fight', fn ($fq) => $fq->where('event_id', $eventId))->pluck('id');
                    $q->whereIn('reference_id', $betIds);
                })
                ->orderByDesc('created_at')
                ->paginate(20)
                ->withQueryString()
            : null;

        $eventNamesByBetId = $transactions
            ? WalletTransactionEventNames::forTransactions($transactions->getCollection())
            : collect();

        // Only offered on the Bets tab, but cheap enough (one player's own
        // events, rarely more than a handful) to just always compute.
        $playerEvents = Event::whereHas('fights.bets', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($request->ajax()) {
            return response()->view('superadmin.wallets._transactions-rows', compact('transactions', 'eventNamesByBetId'));
        }

        return view('superadmin.wallets.transactions', [
            'targetUser' => $user,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'eventNamesByBetId' => $eventNamesByBetId,
            'tab' => $tab,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
            'eventId' => $eventId,
            'playerEvents' => $playerEvents,
        ]);
    }

    public function credit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01']);

        $this->walletService->credit(
            $user->wallet,
            $data['amount'],
            'admin_topup',
            null,
            'Manual top-up by superadmin'
        );

        return back()->with('success', __('Credited :amount to :name.', ['amount' => '$'.$data['amount'], 'name' => $user->displayName()]));
    }

    public function debit(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01']);

        try {
            $this->walletService->debit(
                $user->wallet,
                $data['amount'],
                'admin_withdraw',
                null,
                'Manual withdrawal by superadmin'
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Debited :amount from :name.', ['amount' => '$'.$data['amount'], 'name' => $user->displayName()]));
    }
}
