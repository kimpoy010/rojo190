<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Services\CashTransactionService;
use App\Services\TellerShiftService;
use App\Support\ScannedCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(
        private CashTransactionService $cashService,
        private TellerShiftService $shiftService,
    ) {}

    public function dashboard(): View|RedirectResponse
    {
        $shift = $this->shiftService->currentShift(auth()->user());

        if (! $shift) {
            return redirect()->route('teller.shift.start')->with('info', __('Start your shift with a starting cash count before processing transactions.'));
        }

        $recent = CashTransaction::where('teller_shift_id', $shift->id)
            ->with('user:id,name,username')
            ->orderByDesc('completed_at')
            ->limit(20)
            ->get();

        // System-wide, not scoped to this teller: an RFID-kiosk top-up
        // never hands the teller a code to scan or type, so any teller
        // needs to be able to see and pick up a pending request directly.
        $pending = CashTransaction::where('status', 'pending')
            ->with('user:id,name,username')
            ->orderBy('created_at')
            ->limit(20)
            ->get();

        $totals = $shift->totals();

        return view('teller.dashboard', compact('recent', 'shift', 'totals', 'pending'));
    }

    /**
     * Manual fallback for when a phone camera isn't available — the teller
     * types/pastes the code shown under the player's QR.
     */
    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        $raw = ScannedCode::extract($data['code']);

        $transaction = CashTransaction::where('code', strtoupper($raw))->first();

        if (! $transaction) {
            return redirect()->route('teller.dashboard')->with('error', __('No cash request found for that code.'));
        }

        return redirect()->route('teller.transactions.show', $transaction);
    }

    public function show(CashTransaction $cashTransaction): View
    {
        if ($cashTransaction->isExpired()) {
            $cashTransaction = $this->cashService->expire($cashTransaction);
        }

        $cashTransaction->load('user:id,name,username', 'user.wallet');

        return view('teller.show', compact('cashTransaction'));
    }

    public function approve(CashTransaction $cashTransaction): RedirectResponse
    {
        $shift = $this->shiftService->currentShift(auth()->user());

        if (! $shift) {
            return redirect()->route('teller.shift.start')->with('error', __('Start your shift before processing transactions.'));
        }

        try {
            $this->cashService->approve($cashTransaction, auth()->user(), $shift);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.transactions.show', $cashTransaction)->with('error', $e->getMessage());
        }

        $label = $cashTransaction->type === 'deposit' ? __('Deposit credited.') : __('Withdrawal approved — hand over the payment.');

        return redirect()->route('teller.dashboard')->with('success', $label);
    }

    public function reject(CashTransaction $cashTransaction): RedirectResponse
    {
        try {
            $this->cashService->cancel($cashTransaction);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.transactions.show', $cashTransaction)->with('error', $e->getMessage());
        }

        return redirect()->route('teller.dashboard')->with('success', __('Request rejected.'));
    }
}
