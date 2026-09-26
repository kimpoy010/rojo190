<?php

namespace App\Http\Controllers\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\RfidTerminal;
use App\Services\RfidKioskService;
use App\Services\RfidScanService;
use App\Support\GameTheme;
use App\Support\PoolPayoutCalculator;
use App\Support\ScannedCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The self-service kiosk screen: no login, protected only by the
 * unguessable terminal token in the URL (same pattern as the QR-code cash
 * pages). A player picks an amount here, then physically taps their card
 * on a reader — the actual bet/top-up happens server-side when the serial
 * bridge relays that tap to Api\RfidScanController, not from anything
 * clicked on this page.
 */
class KioskController extends Controller
{
    public function __construct(
        private RfidKioskService $kioskService,
        private RfidScanService $scanService,
    ) {}

    public function show(RfidTerminal $rfidTerminal): View
    {
        abort_unless($rfidTerminal->is_active, 404);

        $event = $rfidTerminal->activeEvent();

        return view('kiosk.show', [
            'terminal' => $rfidTerminal,
            'fight' => $rfidTerminal->openFight(),
            'event' => $event,
            'theme' => ($event?->game)?->theme() ?? GameTheme::for(null),
        ] + $this->poolData($rfidTerminal));
    }

    /**
     * A self-service "tap to check your balance" screen — no bet/top-up UI,
     * just a big banner filled in live by a 'balance' reader's tap (see
     * RfidScanService::handleBalanceCheck). Independent of any open fight.
     */
    public function balance(RfidTerminal $rfidTerminal): View
    {
        abort_unless($rfidTerminal->is_active, 404);

        return view('kiosk.balance', ['terminal' => $rfidTerminal]);
    }

    /**
     * Fallback for the balance kiosk when a player has no card (or the
     * reader's unreachable): type/paste the reference code shown under
     * their profile QR, or scan that QR directly — same handheld-scanner
     * URL-vs-bare-code wrinkle as every other manual lookup box in the app.
     */
    public function lookupBalance(Request $request, RfidTerminal $rfidTerminal): JsonResponse
    {
        abort_unless($rfidTerminal->is_active, 404);

        $data = $request->validate(['code' => 'required|string']);
        $code = ScannedCode::extract($data['code']);

        $result = $this->scanService->lookupBalanceByCode($code);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function status(RfidTerminal $rfidTerminal): JsonResponse
    {
        $fight = $rfidTerminal->openFight();

        if (! $fight) {
            return response()->json(['open' => false]);
        }

        return response()->json(['open' => true, 'fight_id' => $fight->id, 'fight_number' => $fight->fight_number] + $this->poolData($rfidTerminal));
    }

    /**
     * Stage an amount for the next tap on this terminal's bet readers
     * (context=bet) or top-up reader (context=topup).
     */
    public function arm(Request $request, RfidTerminal $rfidTerminal): JsonResponse
    {
        $data = $request->validate([
            'context' => 'required|in:bet,topup',
            'amount' => 'required|numeric|min:1',
        ]);

        $this->kioskService->arm($rfidTerminal, $data['context'], (float) $data['amount']);

        return response()->json(['success' => true]);
    }

    private function poolData(RfidTerminal $rfidTerminal): array
    {
        $fight = $rfidTerminal->openFight();

        if (! $fight) {
            return ['meronPool' => 0.0, 'walaPool' => 0.0, 'payouts' => ['meron' => 0.0, 'wala' => 0.0, 'meron_pct' => 0.0, 'wala_pct' => 0.0]];
        }

        $game = $fight->event->game;
        $meronPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'meron')->sum('amount');
        $walaPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'wala')->sum('amount');
        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';

        return [
            'meronPool' => $meronPool,
            'walaPool' => $walaPool,
            'payouts' => PoolPayoutCalculator::calculate($meronPool, $walaPool, $plasada, $plasadaMode),
        ];
    }
}
