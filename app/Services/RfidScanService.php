<?php

namespace App\Services;

use App\Events\KioskScanResult;
use App\Events\PlayerIdentified;
use App\Models\RfidTerminal;
use App\Models\User;
use App\Support\Broadcaster;

/**
 * Turns a single physical card tap (posted directly by an ESP32 reader
 * board over WiFi) into the actual wallet action, using whatever amount
 * the kiosk screen most recently armed for that reader. Always returns a
 * result — for both the board's HTTP response and the kiosk screen's live
 * broadcast — so a denied tap (unregistered card, no amount selected,
 * betting closed, insufficient balance...) is always explained, never
 * silent.
 */
class RfidScanService
{
    public function __construct(
        private RfidKioskService $kioskService,
        private BettingService $bettingService,
        private CashTransactionService $cashService,
    ) {}

    public function handle(RfidTerminal $terminal, string $reader, string $tagUid): array
    {
        $player = User::where('rfid_uid', $tagUid)->first();

        if (! $player) {
            return $this->fail($terminal, __('Card not registered. See a teller to link this card to your account.'));
        }

        return match ($reader) {
            'meron', 'wala' => $this->handleBet($terminal, $player, $reader),
            'topup' => $this->handleTopUp($terminal, $player),
            'identify' => $this->handleIdentify($terminal, $player),
            'balance' => $this->handleBalanceCheck($terminal, $player),
            default => $this->fail($terminal, __('Unknown reader: :reader.', ['reader' => $reader])),
        };
    }

    /**
     * A self-service balance-check reader: no bet or top-up happens here,
     * just reads the wallet back to the kiosk.{token} screen — same
     * broadcast/channel every other kiosk result uses, so kiosk/balance.blade.php
     * needs nothing beyond the ordinary KioskScanResult listener.
     */
    private function handleBalanceCheck(RfidTerminal $terminal, User $player): array
    {
        [$message, $details] = $this->balanceMessage($player);

        return $this->succeed($terminal, $message, $details);
    }

    /**
     * Same balance lookup as a 'balance' reader tap, but keyed off a
     * player's profile code (typed, or pulled from their profile QR)
     * instead of an RFID tag — for the kiosk balance screen's fallback
     * input when a player doesn't have a card, or the reader's unreachable.
     * A direct request/response, not a tap relayed by a reader board, so
     * this doesn't broadcast — the kiosk page shows it immediately from
     * the fetch response itself.
     */
    public function lookupBalanceByCode(string $code): array
    {
        $player = User::role('player')->where('player_code', strtoupper($code))->first();

        if (! $player) {
            return ['success' => false, 'message' => __('No account found for that code.'), 'details' => []];
        }

        [$message, $details] = $this->balanceMessage($player);

        return ['success' => true, 'message' => $message, 'details' => $details];
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function balanceMessage(User $player): array
    {
        $wallet = $player->wallet;
        $available = (float) ($wallet?->availableBalance() ?? 0);

        $message = __(':name — $:amount available.', [
            'name' => $player->displayName(),
            'amount' => number_format($available, 2),
        ]);

        $details = [
            'player' => $player->displayName(),
            'wallet_balance' => (float) ($wallet->main_balance ?? 0),
            'available_balance' => $available,
        ];

        return [$message, $details];
    }

    /**
     * A teller-counter reader: just identifies the player on that
     * station's screen so the teller can drive a deposit/withdrawal from
     * there — no amount is armed and no wallet action happens here.
     */
    private function handleIdentify(RfidTerminal $terminal, User $player): array
    {
        $message = __('Identified :name.', ['name' => $player->displayName()]);
        Broadcaster::send(new PlayerIdentified($terminal, $player));

        return ['success' => true, 'message' => $message, 'details' => ['player' => $player->displayName()]];
    }

    private function handleBet(RfidTerminal $terminal, User $player, string $side): array
    {
        $amount = $this->kioskService->armedAmount($terminal, 'bet');

        if ($amount === null) {
            return $this->fail($terminal, __('No amount selected. Choose an amount on screen, then tap your card.'));
        }

        $fight = $terminal->openFight();

        if (! $fight) {
            return $this->fail($terminal, __('Betting is not open right now.'));
        }

        try {
            $bet = $this->bettingService->placeBet($player, $fight, $side, $amount);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($terminal, $e->getMessage());
        }

        $this->kioskService->clear($terminal, 'bet');

        return $this->succeed($terminal, __(':amount on :side for :name.', ['amount' => '$'.number_format($amount, 2), 'side' => strtoupper($side), 'name' => $player->displayName()]), [
            'player' => $player->displayName(),
            'side' => $bet->side,
            'amount' => (float) $bet->amount,
            'wallet_balance' => (float) $player->wallet->fresh()->main_balance,
        ]);
    }

    private function handleTopUp(RfidTerminal $terminal, User $player): array
    {
        $amount = $this->kioskService->armedAmount($terminal, 'topup');

        if ($amount === null) {
            return $this->fail($terminal, __('No amount entered. Enter a top-up amount on screen, then tap your card.'));
        }

        try {
            $transaction = $this->cashService->createDeposit($player, $amount, 'rfid');
        } catch (\InvalidArgumentException $e) {
            return $this->fail($terminal, $e->getMessage());
        }

        $this->kioskService->clear($terminal, 'topup');

        return $this->succeed($terminal, __('Top-up request for :amount submitted for :name — show this screen to a teller to complete it.', ['amount' => '$'.number_format($amount, 2), 'name' => $player->displayName()]), [
            'player' => $player->displayName(),
            'amount' => (float) $transaction->amount,
            'code' => $transaction->code,
        ]);
    }

    private function succeed(RfidTerminal $terminal, string $message, array $details): array
    {
        $result = ['success' => true, 'message' => $message, 'details' => $details];
        Broadcaster::send(new KioskScanResult($terminal, true, $message, $details));

        return $result;
    }

    private function fail(RfidTerminal $terminal, string $message): array
    {
        $result = ['success' => false, 'message' => $message, 'details' => []];
        Broadcaster::send(new KioskScanResult($terminal, false, $message));

        return $result;
    }
}
