<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\CashTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TellerTransactionLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'player']);
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function player(): User
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        return $player;
    }

    public function test_lookup_by_code_still_works(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $tx = app(CashTransactionService::class)->createDeposit($player, 500);

        $response = $this->actingAs($teller)->post(route('teller.lookup'), [
            'code' => strtolower($tx->code),
        ]);

        $response->assertRedirect(route('teller.transactions.show', $tx));
    }

    public function test_lookup_accepts_the_full_qr_url_a_handheld_scanner_types_out(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $tx = app(CashTransactionService::class)->createDeposit($player, 500);

        $response = $this->actingAs($teller)->post(route('teller.lookup'), [
            'code' => route('teller.transactions.show', $tx),
        ]);

        $response->assertRedirect(route('teller.transactions.show', $tx));
    }

    public function test_lookup_accepts_a_url_with_a_trailing_slash_or_query_string(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $tx = app(CashTransactionService::class)->createDeposit($player, 500);

        $response = $this->actingAs($teller)->post(route('teller.lookup'), [
            'code' => route('teller.transactions.show', $tx).'/?utm_source=scanner',
        ]);

        $response->assertRedirect(route('teller.transactions.show', $tx));
    }
}
