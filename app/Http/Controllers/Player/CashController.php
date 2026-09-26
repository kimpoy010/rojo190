<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Models\CashTransaction;
use App\Services\CashTransactionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CashController extends Controller
{
    public function __construct(private CashTransactionService $cashService) {}

    public function index(): View
    {
        $wallet = auth()->user()->wallet;
        $pending = CashTransaction::where('user_id', auth()->id())->where('status', 'pending')->first();

        if ($pending && $pending->isExpired()) {
            $pending = $this->cashService->expire($pending);
        }

        $history = CashTransaction::where('user_id', auth()->id())
            ->whereNotIn('status', ['pending'])
            ->with('teller:id,name,username')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('player.cash.index', compact('wallet', 'pending', 'history'));
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:20',
        ]);

        try {
            $transaction = $this->cashService->createDeposit(auth()->user(), (float) $data['amount']);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('play.cash.index')->with('error', $e->getMessage());
        }

        return redirect()->route('play.cash.show', $transaction);
    }

    public function storeWithdrawal(): RedirectResponse
    {
        try {
            $transaction = $this->cashService->createWithdrawal(auth()->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('play.cash.index')->with('error', $e->getMessage());
        }

        return redirect()->route('play.cash.show', $transaction);
    }

    public function show(CashTransaction $cashTransaction): View|RedirectResponse
    {
        abort_if($cashTransaction->user_id !== auth()->id(), 403);

        if ($cashTransaction->isExpired()) {
            $cashTransaction = $this->cashService->expire($cashTransaction);
        }

        return view('player.cash.show', compact('cashTransaction'));
    }

    public function status(CashTransaction $cashTransaction): JsonResponse
    {
        abort_if($cashTransaction->user_id !== auth()->id(), 403);

        if ($cashTransaction->isExpired()) {
            $cashTransaction = $this->cashService->expire($cashTransaction);
        }

        return response()->json(['status' => $cashTransaction->status]);
    }

    public function cancel(CashTransaction $cashTransaction): RedirectResponse
    {
        abort_if($cashTransaction->user_id !== auth()->id(), 403);

        try {
            $this->cashService->cancel($cashTransaction);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('play.cash.index')->with('error', $e->getMessage());
        }

        return redirect()->route('play.cash.index')->with('success', __('Request cancelled.'));
    }
}
