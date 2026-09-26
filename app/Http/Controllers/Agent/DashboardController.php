<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Services\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    public function index(): View
    {
        $agent = auth()->user();

        $downlineAgents = $agent->downline()->role('agent')->withCount('downline')->get();
        $downlinePlayers = $agent->downline()->role('player')->orderBy('name')->paginate(15, ['*'], 'players_page');

        $logs = CommissionLog::where('agent_id', $agent->id)
            ->with('player:id,name,username')
            ->orderByDesc('credited_at')
            ->limit(25)
            ->get();

        $totalEarned = CommissionLog::where('agent_id', $agent->id)->sum('amount');
        $thisMonth = CommissionLog::where('agent_id', $agent->id)
            ->whereMonth('credited_at', now()->month)
            ->whereYear('credited_at', now()->year)
            ->sum('amount');

        $referralUrl = $agent->referral_code ? route('register', ['ref' => $agent->referral_code]) : null;

        return view('agent.dashboard', compact(
            'agent', 'downlineAgents', 'downlinePlayers', 'logs', 'totalEarned', 'thisMonth', 'referralUrl'
        ));
    }

    public function transfer(WalletService $walletService): RedirectResponse
    {
        try {
            $amount = $walletService->transferCommissionToMain(auth()->user()->wallet);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Transferred :amount to your main balance.', ['amount' => '$'.number_format($amount, 2)]));
    }
}
