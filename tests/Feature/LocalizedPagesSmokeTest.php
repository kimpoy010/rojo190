<?php

namespace Tests\Feature;

use App\Models\AgentLevel;
use App\Models\Bet;
use App\Models\CashTransaction;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\RfidTerminal;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\CashTransactionService;
use App\Services\TellerShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Renders every page-producing GET route in both locales to catch blade
 * compile errors the i18n sweep could have introduced (a bad @json(...)
 * call, a stray brace, a translation placeholder mismatch) that unit-level
 * feature tests wouldn't otherwise exercise.
 */
class LocalizedPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $declarator;
    private User $teller;
    private User $player;
    private User $agent;
    private Event $event;
    private Fight $fight;
    private RfidTerminal $terminal;
    private CashTransaction $cashTransaction;
    private Bet $ticket;
    private \App\Models\TellerShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['player', 'declarator', 'superadmin', 'agent', 'teller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'region' => 'philippines',
            'plasada' => 5.00, 'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);

        $this->superadmin = User::factory()->create(['pin' => '1234']);
        $this->superadmin->assignRole('superadmin');
        Wallet::create(['user_id' => $this->superadmin->id, 'main_balance' => 1_000_000]);

        $this->declarator = User::factory()->create();
        $this->declarator->assignRole('declarator');

        $this->teller = User::factory()->create();
        $this->teller->assignRole('teller');
        Wallet::create(['user_id' => $this->teller->id]);
        $this->shift = app(TellerShiftService::class)->startShift($this->teller, 5000);

        $this->player = User::factory()->create();
        $this->player->assignRole('player');
        Wallet::create(['user_id' => $this->player->id, 'main_balance' => 500]);

        $level = AgentLevel::create(['level' => 1, 'label' => 'Level 1', 'commission_rate' => 2.00]);
        $this->agent = User::factory()->create();
        $this->agent->assignRole('agent');
        $this->agent->update(['agent_level_id' => $level->id]);
        Wallet::create(['user_id' => $this->agent->id]);

        $this->event = Event::create([
            'game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true,
        ]);
        $this->fight = Fight::create([
            'event_id' => $this->event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true,
        ]);

        $this->terminal = RfidTerminal::create(['name' => 'Terminal 1', 'token' => RfidTerminal::generateToken(), 'is_active' => true]);

        $this->cashTransaction = app(CashTransactionService::class)->createDeposit($this->player, 200, 'teller');

        $this->ticket = app(BettingService::class)->placeCounterBet($this->teller, $this->shift, $this->fight, 'meron', 100);
    }

    /** @return array<int, array{0: string}> */
    public static function localeProvider(): array
    {
        return [['en'], ['es']];
    }

    #[DataProvider('localeProvider')]
    public function test_player_pages_render(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        foreach ([
            route('play.index'),
            route('play.pool-fight', $this->fight),
            route('play.wallet.index'),
            route('play.profile'),
            route('play.cash.index'),
            route('play.cash.show', $this->cashTransaction),
        ] as $url) {
            $this->actingAs($this->player)->get($url)->assertSuccessful();
        }

        // Redirects straight to the live fight — not a page render itself.
        $this->actingAs($this->player)->get(route('play.events.enter', $this->event))
            ->assertRedirect(route('play.pool-fight', $this->fight));
    }

    #[DataProvider('localeProvider')]
    public function test_declarator_pages_render(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        foreach ([
            route('declarator.events.index'),
            route('declarator.events.show', $this->event),
        ] as $url) {
            $this->actingAs($this->declarator)->get($url)->assertSuccessful();
        }
    }

    #[DataProvider('localeProvider')]
    public function test_teller_pages_render(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        foreach ([
            route('teller.dashboard'),
            route('teller.rfid.index'),
            route('teller.rfid.link', $this->player->profileCode()),
            route('teller.shift.end'),
            route('teller.shifts.history'),
            route('teller.shift.report', $this->shift),
            route('teller.station.index'),
            route('teller.station.show', $this->terminal),
            route('teller.tickets.create'),
            route('teller.tickets.show', $this->ticket),
            route('teller.tickets.receipt', $this->ticket),
            route('teller.transactions.show', $this->cashTransaction),
        ] as $url) {
            $this->actingAs($this->teller)->get($url)->assertSuccessful();
        }
    }

    #[DataProvider('localeProvider')]
    public function test_teller_shift_start_page_renders_for_a_teller_with_no_open_shift(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        $freshTeller = User::factory()->create();
        $freshTeller->assignRole('teller');
        Wallet::create(['user_id' => $freshTeller->id]);

        $this->actingAs($freshTeller)->get(route('teller.shift.start'))->assertSuccessful();
    }

    #[DataProvider('localeProvider')]
    public function test_superadmin_pages_render(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        foreach ([
            route('superadmin.dashboard'),
            route('superadmin.agents.index'),
            route('superadmin.agents.create'),
            route('superadmin.events.create'),
            route('superadmin.events.edit', $this->event),
            route('superadmin.games.edit', Game::first()),
            route('superadmin.pin.edit'),
            route('superadmin.rfid-terminals.index'),
            route('superadmin.wallets.index'),
        ] as $url) {
            $this->actingAs($this->superadmin)->get($url)->assertSuccessful();
        }
    }

    #[DataProvider('localeProvider')]
    public function test_agent_dashboard_renders(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        $this->actingAs($this->agent)->get(route('agent.dashboard'))->assertSuccessful();
    }

    #[DataProvider('localeProvider')]
    public function test_kiosk_and_guest_pages_render(string $locale): void
    {
        $this->get(route('locale.switch', $locale));

        $this->get(route('kiosk.show', $this->terminal))->assertSuccessful();
        $this->get(route('login'))->assertSuccessful();
        $this->get(route('register'))->assertSuccessful();
    }
}
