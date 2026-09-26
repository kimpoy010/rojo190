<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\RfidTerminal;
use App\Models\TellerShift;
use App\Models\User;
use App\Services\CashTransactionService;
use App\Services\TellerShiftService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A teller's own counter reader ("identify" role): tapping a player's card
 * shows that player on this screen (live, via broadcast) so the teller can
 * drive a one-step deposit or withdrawal — the teller physically present
 * *is* the approval, unlike the app/kiosk QR flow which stages a request
 * for later approval.
 */
class StationController extends Controller
{
    public function __construct(
        private CashTransactionService $cashService,
        private TellerShiftService $shiftService,
    ) {}

    public function index(): View
    {
        $terminals = RfidTerminal::where('is_active', true)->orderBy('name')->get();

        return view('teller.station.index', compact('terminals'));
    }

    public function show(RfidTerminal $rfidTerminal): View
    {
        return view('teller.station.show', ['terminal' => $rfidTerminal]);
    }

    public function deposit(Request $request, RfidTerminal $rfidTerminal, User $player): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:1']);

        $shift = $this->requireOpenShift();
        if ($shift instanceof RedirectResponse) {
            return $shift;
        }

        try {
            $this->cashService->instantDeposit($player, (float) $data['amount'], auth()->user(), $shift, 'rfid');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.station.show', $rfidTerminal)->with('error', $e->getMessage());
        }

        return redirect()->route('teller.station.show', $rfidTerminal)
            ->with('success', __(':amount deposited for :name.', ['amount' => '$'.number_format($data['amount'], 2), 'name' => $player->displayName()]));
    }

    public function withdraw(RfidTerminal $rfidTerminal, User $player): RedirectResponse
    {
        $shift = $this->requireOpenShift();
        if ($shift instanceof RedirectResponse) {
            return $shift;
        }

        try {
            $transaction = $this->cashService->instantWithdrawal($player, auth()->user(), $shift, 'rfid');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('teller.station.show', $rfidTerminal)->with('error', $e->getMessage());
        }

        return redirect()->route('teller.station.show', $rfidTerminal)
            ->with('success', __(':amount withdrawn for :name.', ['amount' => '$'.number_format((float) $transaction->amount, 2), 'name' => $player->displayName()]));
    }

    private function requireOpenShift(): TellerShift|RedirectResponse
    {
        $shift = $this->shiftService->currentShift(auth()->user());

        if (! $shift) {
            return redirect()->route('teller.shift.start')->with('error', __('Start your shift before processing transactions.'));
        }

        return $shift;
    }
}
