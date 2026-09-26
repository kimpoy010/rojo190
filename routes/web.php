<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Agent\DashboardController as AgentDashboardController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Declarator\EventController as DeclaratorEventController;
use App\Http\Controllers\Declarator\FightController as DeclaratorFightController;
use App\Http\Controllers\Declarator\CombinedFightController as DeclaratorCombinedFightController;
use App\Http\Controllers\Kiosk\KioskController;
use App\Http\Controllers\Player\CashController;
use App\Http\Controllers\Player\CombinedBetController;
use App\Http\Controllers\Player\EventController as PlayerEventController;
use App\Http\Controllers\Player\PoolBetController;
use App\Http\Controllers\Player\ProfileController as PlayerProfileController;
use App\Http\Controllers\Player\WalletController as PlayerWalletController;
use App\Http\Controllers\Superadmin\AccountingController;
use App\Http\Controllers\Superadmin\AgentController as SuperadminAgentController;
use App\Http\Controllers\Superadmin\CockpitController;
use App\Http\Controllers\Superadmin\CockpitPresetController;
use App\Http\Controllers\Superadmin\DashboardController;
use App\Http\Controllers\Superadmin\EventController as SuperadminEventController;
use App\Http\Controllers\Superadmin\GameController;
use App\Http\Controllers\Superadmin\IncomeReportController;
use App\Http\Controllers\Superadmin\AuditLogController;
use App\Http\Controllers\Superadmin\OddsTierController;
use App\Http\Controllers\Superadmin\PinController;
use App\Http\Controllers\Superadmin\SettingsController;
use App\Http\Controllers\Superadmin\RfidTerminalController;
use App\Http\Controllers\Superadmin\StaffController;
use App\Http\Controllers\Superadmin\TellerCashFlowReportController;
use App\Http\Controllers\Superadmin\WalletController;
use App\Http\Controllers\Teller\RfidCardController;
use App\Http\Controllers\Teller\ShiftController as TellerShiftController;
use App\Http\Controllers\Teller\StationController as TellerStationController;
use App\Http\Controllers\Teller\TicketController as TellerTicketController;
use App\Http\Controllers\Teller\TransactionController as TellerTransactionController;
use Illuminate\Support\Facades\Route;

// Auth-aware, not a blanket redirect: an authenticated user hitting "/" must
// never be sent to "/login", because the guest middleware bounces
// authenticated users on "/login" straight back to "/" (there's no
// dashboard/home route to fall back to) — a blanket redirect here would
// create an infinite redirect loop for anyone with an existing session.
Route::get('/', function () {
    return redirect()->route(auth()->check() ? auth()->user()->homeRouteName() : 'login');
});

// No auth required — the language switcher has to work on the no-login
// kiosk screen too, not just for logged-in roles.
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'es'], true)) {
        session(['locale' => $locale]);
    }

    return back();
})->where('locale', 'en|es')->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('throttle:60,1');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [RegisterController::class, 'show'])->name('register')->middleware('throttle:30,1');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:10,1');

    Route::get('/forgot-password', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request')->middleware('throttle:30,1');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email')->middleware('throttle:6,1');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset')->middleware('throttle:30,1');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update')->middleware('throttle:6,1');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Shared across every role — unlike everything else in this file, which is
// scoped to one role's prefix/middleware.
Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/password', [AccountController::class, 'editPassword'])->name('password.edit');
    Route::put('/password', [AccountController::class, 'updatePassword'])->name('password.update')->middleware('throttle:10,1');
});

// Self-service RFID kiosk — no login. Protected only by the unguessable
// terminal token in the URL, same pattern as the QR cash-transaction pages.
Route::prefix('kiosk')->name('kiosk.')->group(function () {
    Route::get('/{rfidTerminal:token}', [KioskController::class, 'show'])->name('show');
    Route::get('/{rfidTerminal:token}/status', [KioskController::class, 'status'])->name('status');
    Route::post('/{rfidTerminal:token}/arm', [KioskController::class, 'arm'])->name('arm')->middleware('throttle:60,1');
    Route::get('/{rfidTerminal:token}/balance', [KioskController::class, 'balance'])->name('balance');
    Route::post('/{rfidTerminal:token}/balance/lookup', [KioskController::class, 'lookupBalance'])->name('balance.lookup')->middleware('throttle:20,1');
});

Route::middleware(['auth', 'role:player'])->prefix('play')->name('play.')->group(function () {
    Route::get('/', [PlayerEventController::class, 'index'])->name('index');
    Route::get('/events/{event}', [PlayerEventController::class, 'enter'])->name('events.enter');
    Route::get('/fight/{fight}', [PoolBetController::class, 'show'])->name('pool-fight');
    Route::get('/fight/{fight}/status', [PoolBetController::class, 'status'])->name('pool-fight.status');
    Route::post('/fight/{fight}/bet', [PoolBetController::class, 'store'])->name('pool-bet')->middleware('throttle:30,1');
    Route::get('/bet-history', [PoolBetController::class, 'betHistory'])->name('bet-history');

    Route::get('/combined-fight/{fight}', [CombinedBetController::class, 'show'])->name('combined-fight');
    Route::get('/combined-fight/{fight}/status', [CombinedBetController::class, 'status'])->name('combined-fight.status');
    Route::post('/combined-fight/{fight}/bet', [CombinedBetController::class, 'store'])->name('combined-bet')->middleware('throttle:30,1');
    Route::post('/combined-fight/{fight}/bets/{bet}/cancel', [CombinedBetController::class, 'cancelBet'])->name('combined-cancel-bet')->middleware('throttle:30,1');

    Route::get('/wallet', [PlayerWalletController::class, 'index'])->name('wallet.index');
    Route::get('/profile', [PlayerProfileController::class, 'show'])->name('profile');

    Route::prefix('cash')->name('cash.')->group(function () {
        Route::get('/', [CashController::class, 'index'])->name('index');
        Route::post('/deposit', [CashController::class, 'storeDeposit'])->name('deposit')->middleware('throttle:10,1');
        Route::post('/withdraw', [CashController::class, 'storeWithdrawal'])->name('withdraw')->middleware('throttle:10,1');
        Route::get('/{cashTransaction:code}', [CashController::class, 'show'])->name('show');
        Route::get('/{cashTransaction:code}/status', [CashController::class, 'status'])->name('status');
        Route::post('/{cashTransaction:code}/cancel', [CashController::class, 'cancel'])->name('cancel');
    });
});

Route::middleware(['auth', 'role:declarator|superadmin'])->prefix('declarator')->name('declarator.')->group(function () {
    Route::get('/events', [DeclaratorEventController::class, 'index'])->name('events.index');
    Route::get('/events/{event}', [DeclaratorEventController::class, 'show'])->name('events.show');
    Route::get('/events/{event}/fights-panel', [DeclaratorEventController::class, 'fightsPanel'])->name('events.fights-panel');
    Route::post('/events/{event}/start', [DeclaratorEventController::class, 'start'])->name('events.start');
    Route::post('/events/{event}/end', [DeclaratorEventController::class, 'end'])->name('events.end');
    Route::post('/events/{event}/fights/start-next', [DeclaratorEventController::class, 'startNextFight'])->name('events.fights.start-next');

    Route::post('/fights/{fight}/open', [DeclaratorFightController::class, 'open'])->name('fights.open');
    Route::post('/fights/{fight}/last-call', [DeclaratorFightController::class, 'lastCall'])->name('fights.last-call');
    Route::post('/fights/{fight}/close', [DeclaratorFightController::class, 'close'])->name('fights.close');
    Route::post('/fights/{fight}/declare', [DeclaratorFightController::class, 'declare'])->name('fights.declare');
    Route::post('/fights/{fight}/toggle-draw', [DeclaratorFightController::class, 'toggleDraw'])->name('fights.toggle-draw');
    Route::post('/fights/{fight}/cancel', [DeclaratorFightController::class, 'cancel'])->name('fights.cancel');
    Route::post('/fights/{fight}/redeclare', [DeclaratorFightController::class, 'redeclare'])->name('fights.redeclare');
    Route::get('/fights/{fight}/bets', [DeclaratorFightController::class, 'bets'])->name('fights.bets');
    Route::post('/fights/{fight}/fight-number', [DeclaratorFightController::class, 'updateFightNumber'])->name('fights.fight-number');
    Route::post('/fights/{fight}/cockpit', [DeclaratorFightController::class, 'updateCockpit'])->name('fights.cockpit');

    // CombinedSabong only — everything else about a fight's lifecycle
    // (open/last-call/close/toggle-draw/cockpit/fight-number/bets above)
    // is game-agnostic and shared as-is; only settlement differs.
    Route::post('/combined-fights/{fight}/close', [DeclaratorCombinedFightController::class, 'close'])->name('combined-fights.close');
    Route::post('/combined-fights/{fight}/declare', [DeclaratorCombinedFightController::class, 'declare'])->name('combined-fights.declare');
    Route::post('/combined-fights/{fight}/cancel', [DeclaratorCombinedFightController::class, 'cancel'])->name('combined-fights.cancel');
    Route::post('/combined-fights/{fight}/redeclare', [DeclaratorCombinedFightController::class, 'redeclare'])->name('combined-fights.redeclare');
});

// Creating and editing an event (including its stream URL) is shared
// between whoever's actually running the event and the superadmin who
// otherwise schedules it, so these live outside both role-specific route
// groups above.
Route::middleware(['auth', 'role:declarator|superadmin'])->group(function () {
    Route::get('/superadmin/events/create', [SuperadminEventController::class, 'create'])->name('superadmin.events.create');
    Route::post('/superadmin/events', [SuperadminEventController::class, 'store'])->name('superadmin.events.store');
    Route::get('/superadmin/events/{event}/edit', [SuperadminEventController::class, 'edit'])->name('superadmin.events.edit');
    Route::put('/superadmin/events/{event}', [SuperadminEventController::class, 'update'])->name('superadmin.events.update');
});

Route::middleware(['auth', 'role:agent'])->prefix('agent')->name('agent.')->group(function () {
    Route::get('/', [AgentDashboardController::class, 'index'])->name('dashboard');
    Route::post('/transfer', [AgentDashboardController::class, 'transfer'])->name('transfer');
});

Route::middleware(['auth', 'role:teller'])->prefix('teller')->name('teller.')->group(function () {
    Route::get('/', [TellerTransactionController::class, 'dashboard'])->name('dashboard');
    Route::post('/lookup', [TellerTransactionController::class, 'lookup'])->name('lookup');
    Route::get('/scan/{cashTransaction:code}', [TellerTransactionController::class, 'show'])->name('transactions.show');
    Route::post('/scan/{cashTransaction:code}/approve', [TellerTransactionController::class, 'approve'])->name('transactions.approve');
    Route::post('/scan/{cashTransaction:code}/reject', [TellerTransactionController::class, 'reject'])->name('transactions.reject');

    Route::prefix('shift')->name('shift.')->group(function () {
        Route::get('/start', [TellerShiftController::class, 'start'])->name('start');
        Route::post('/start', [TellerShiftController::class, 'store'])->name('store');
        Route::get('/end', [TellerShiftController::class, 'end'])->name('end');
        Route::post('/end', [TellerShiftController::class, 'close'])->name('close');
        Route::get('/{tellerShift}/report', [TellerShiftController::class, 'report'])->name('report');
        Route::get('/{tellerShift}/report/export', [TellerShiftController::class, 'export'])->name('report.export');
    });
    Route::get('/shifts', [TellerShiftController::class, 'history'])->name('shifts.history');

    Route::prefix('rfid')->name('rfid.')->group(function () {
        Route::get('/', [RfidCardController::class, 'index'])->name('index');
        Route::get('/search', [RfidCardController::class, 'search'])->name('search')->middleware('throttle:60,1');
        Route::post('/lookup', [RfidCardController::class, 'lookupCode'])->name('lookup')->middleware('throttle:30,1');
        Route::get('/link/{user:player_code}', [RfidCardController::class, 'linkForm'])->name('link');
        Route::post('/', [RfidCardController::class, 'store'])->name('store');
        Route::delete('/{user}', [RfidCardController::class, 'destroy'])->name('destroy');
    });

    // A teller's own counter reader (role: identify) — taps a player's
    // card to drive a one-step deposit/withdrawal, distinct from the
    // self-service kiosk terminals.
    Route::prefix('station')->name('station.')->group(function () {
        Route::get('/', [TellerStationController::class, 'index'])->name('index');
        Route::get('/{rfidTerminal:token}', [TellerStationController::class, 'show'])->name('show');
        Route::post('/{rfidTerminal:token}/players/{player}/deposit', [TellerStationController::class, 'deposit'])->name('deposit')->middleware('throttle:30,1');
        Route::post('/{rfidTerminal:token}/players/{player}/withdraw', [TellerStationController::class, 'withdraw'])->name('withdraw')->middleware('throttle:30,1');
    });

    // Over-the-counter bet tickets — no player account involved.
    Route::prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/write', [TellerTicketController::class, 'create'])->name('create');
        Route::get('/write/status', [TellerTicketController::class, 'status'])->name('status');
        Route::post('/write', [TellerTicketController::class, 'store'])->name('store')->middleware('throttle:30,1');
        Route::post('/lookup', [TellerTicketController::class, 'lookup'])->name('lookup')->middleware('throttle:30,1');
        Route::get('/{bet:ticket_code}/receipt', [TellerTicketController::class, 'receipt'])->name('receipt');
        Route::get('/{bet:ticket_code}', [TellerTicketController::class, 'show'])->name('show');
        Route::post('/{bet:ticket_code}/redeem', [TellerTicketController::class, 'redeem'])->name('redeem')->middleware('throttle:30,1');
        // Stacked limits: a short burst cap (5/min) so a legitimate mistyped
        // PIN isn't blocked, and a much tighter sustained cap (30/day) so a
        // 4-digit PIN (10,000 possibilities) can't be brute-forced in hours
        // the way 10/min unbounded would allow — 30/day stretches an
        // exhaustive guess to roughly a year, well past what's practical.
        // Distinct prefixes are required — two throttle middleware without
        // one share the same signature key and silently corrupt each
        // other's count.
        Route::post('/{bet:ticket_code}/void', [TellerTicketController::class, 'void'])->name('void')->middleware(['throttle:5,1,void-burst', 'throttle:30,1440,void-daily']);
    });
});

Route::middleware(['auth', 'role:superadmin'])->prefix('superadmin')->name('superadmin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/games/{game}/edit', [GameController::class, 'edit'])->name('games.edit');
    Route::put('/games/{game}', [GameController::class, 'update'])->name('games.update');
    Route::get('/cockpits', [CockpitController::class, 'index'])->name('cockpits.index');
    Route::post('/cockpits', [CockpitController::class, 'store'])->name('cockpits.store');
    Route::put('/cockpits/{cockpit}', [CockpitController::class, 'update'])->name('cockpits.update');
    Route::delete('/cockpits/{cockpit}', [CockpitController::class, 'destroy'])->name('cockpits.destroy');

    Route::get('/odds-tiers', [OddsTierController::class, 'index'])->name('odds-tiers.index');
    Route::post('/odds-tiers', [OddsTierController::class, 'store'])->name('odds-tiers.store');
    Route::post('/odds-tiers/reorder', [OddsTierController::class, 'reorder'])->name('odds-tiers.reorder');
    Route::put('/odds-tiers/{oddsTier}', [OddsTierController::class, 'update'])->name('odds-tiers.update');
    Route::delete('/odds-tiers/{oddsTier}', [OddsTierController::class, 'destroy'])->name('odds-tiers.destroy');

    Route::get('/cockpit-presets', [CockpitPresetController::class, 'index'])->name('cockpit-presets.index');
    Route::post('/cockpit-presets', [CockpitPresetController::class, 'store'])->name('cockpit-presets.store');
    Route::put('/cockpit-presets/{cockpitPreset}', [CockpitPresetController::class, 'update'])->name('cockpit-presets.update');
    Route::delete('/cockpit-presets/{cockpitPreset}', [CockpitPresetController::class, 'destroy'])->name('cockpit-presets.destroy');

    Route::get('/reports/income', [IncomeReportController::class, 'index'])->name('reports.income');
    Route::get('/reports/income/export', [IncomeReportController::class, 'export'])->name('reports.income.export');
    Route::get('/reports/accounting/events', [AccountingController::class, 'events'])->name('reports.accounting.events');
    Route::get('/reports/accounting/events/export', [AccountingController::class, 'exportEvents'])->name('reports.accounting.events.export');
    Route::get('/reports/accounting/fights/{fight}', [AccountingController::class, 'fightBets'])->name('reports.accounting.fight');
    Route::get('/reports/accounting/fights/{fight}/export', [AccountingController::class, 'exportFightBets'])->name('reports.accounting.fight.export');
    Route::get('/reports/teller-cash-flow', [TellerCashFlowReportController::class, 'index'])->name('reports.teller-cash-flow');
    Route::get('/reports/teller-cash-flow/export', [TellerCashFlowReportController::class, 'export'])->name('reports.teller-cash-flow.export');

    Route::get('/wallets', [WalletController::class, 'index'])->name('wallets.index');
    Route::get('/wallets/{user}/transactions', [WalletController::class, 'transactions'])->name('wallets.transactions');
    Route::post('/wallets/{user}/credit', [WalletController::class, 'credit'])->name('wallets.credit');
    Route::post('/wallets/{user}/debit', [WalletController::class, 'debit'])->name('wallets.debit');

    Route::get('/pin', [PinController::class, 'edit'])->name('pin.edit');
    Route::put('/pin', [PinController::class, 'update'])->name('pin.update');

    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/agents', [SuperadminAgentController::class, 'index'])->name('agents.index');
    Route::get('/agents/create', [SuperadminAgentController::class, 'create'])->name('agents.create');
    Route::post('/agents', [SuperadminAgentController::class, 'store'])->name('agents.store');
    Route::post('/agents/{user}/level', [SuperadminAgentController::class, 'setLevel'])->name('agents.set-level');
    Route::post('/agents/{user}/rate', [SuperadminAgentController::class, 'setRate'])->name('agents.set-rate');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{user}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{user}/toggle-status', [StaffController::class, 'toggleStatus'])->name('staff.toggle-status');

    Route::prefix('rfid-terminals')->name('rfid-terminals.')->group(function () {
        Route::get('/', [RfidTerminalController::class, 'index'])->name('index');
        Route::post('/', [RfidTerminalController::class, 'store'])->name('store');
        Route::post('/{rfidTerminal}/toggle', [RfidTerminalController::class, 'toggle'])->name('toggle');
        Route::post('/{rfidTerminal}/regenerate-token', [RfidTerminalController::class, 'regenerateToken'])->name('regenerate-token');
        Route::post('/{rfidTerminal}/readers/{rfidReader}/role', [RfidTerminalController::class, 'assignReaderRole'])->name('readers.role');
        Route::delete('/{rfidTerminal}/readers/{rfidReader}', [RfidTerminalController::class, 'destroyReader'])->name('readers.destroy');
        Route::delete('/{rfidTerminal}', [RfidTerminalController::class, 'destroy'])->name('destroy');
    });
});
