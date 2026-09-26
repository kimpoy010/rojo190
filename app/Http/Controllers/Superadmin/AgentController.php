<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AgentCommissionRate;
use App\Models\AgentLevel;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    public function index(): View
    {
        $agents = User::role('agent')
            ->with(['commissionRates.game', 'agentLevel', 'agent:id,username'])
            ->withCount('downline')
            ->orderBy('name')
            ->get();

        $agentLevels = AgentLevel::orderBy('level')->get();
        $game = Game::where('game_name', 'pool-sabong')->first();
        $possibleUplines = User::role('agent')->orderBy('username')->get(['id', 'username', 'name']);

        return view('superadmin.agents.index', compact('agents', 'agentLevels', 'game', 'possibleUplines'));
    }

    public function create(): View
    {
        $agentLevels = AgentLevel::orderBy('level')->get();
        $possibleUplines = User::role('agent')->orderBy('username')->get(['id', 'username', 'name']);

        return view('superadmin.agents.create', compact('agentLevels', 'possibleUplines'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'agent_level_id' => 'nullable|exists:agent_levels,id',
            'agent_id' => 'nullable|exists:users,id',
        ]);

        $game = Game::where('game_name', 'pool-sabong')->first();
        $level = $data['agent_level_id'] ? AgentLevel::find($data['agent_level_id']) : null;

        $agent = DB::transaction(function () use ($data, $level, $game) {
            $agent = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'agent_id' => $data['agent_id'] ?? null,
                'agent_level_id' => $data['agent_level_id'] ?? null,
                'referral_code' => Str::upper(Str::random(8)),
            ]);
            $agent->assignRole('agent');

            Wallet::create(['user_id' => $agent->id]);

            if ($level && $game) {
                AgentCommissionRate::create([
                    'agent_id' => $agent->id,
                    'game_id' => $game->id,
                    'commission_rate' => $level->commission_rate,
                ]);
            }

            return $agent;
        });

        AuditLogger::log(
            action: 'agent.created',
            description: __('Agent :username created.', ['username' => $agent->username]),
            target: $agent,
        );

        return redirect()->route('superadmin.agents.index')->with('success', __('Agent :username created.', ['username' => $agent->username]));
    }

    public function setLevel(Request $request, User $user): RedirectResponse
    {
        abort_if(! $user->hasRole('agent'), 422, 'Target user is not an agent.');

        $request->validate(['agent_level_id' => 'nullable|exists:agent_levels,id']);

        $oldLevelId = $user->agent_level_id;
        $newLevelId = $request->agent_level_id ?: null;
        $user->update(['agent_level_id' => $newLevelId]);

        AuditLogger::log(
            action: 'agent.level_changed',
            description: __('Agent level updated for :name.', ['name' => $user->displayName()]),
            target: $user,
            changes: ['agent_level_id' => ['old' => $oldLevelId, 'new' => $newLevelId]],
        );

        return back()->with('success', __('Agent level updated for :name.', ['name' => $user->displayName()]));
    }

    public function setRate(Request $request, User $user): RedirectResponse
    {
        abort_if(! $user->hasRole('agent'), 422, 'Target user is not an agent.');

        $data = $request->validate([
            'game_id' => 'required|exists:games,id',
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $game = Game::find($data['game_id']);
        $maxRate = (float) ($game->plasada ?? 100);

        if ((float) $data['commission_rate'] > $maxRate) {
            return back()->with('error', __('Max commission for :game is :rate% (its plasada rate).', ['game' => $game->display_name, 'rate' => $maxRate]));
        }

        $oldRate = AgentCommissionRate::where('agent_id', $user->id)->where('game_id', $data['game_id'])->value('commission_rate');

        AgentCommissionRate::updateOrCreate(
            ['agent_id' => $user->id, 'game_id' => $data['game_id']],
            ['commission_rate' => $data['commission_rate']]
        );

        // Cap all downline agents' rates to the new ceiling so the differential
        // model can never pay a downline more than their upline earns.
        AgentCommissionRate::cascadeDown($user, (int) $data['game_id'], (float) $data['commission_rate']);

        AuditLogger::log(
            action: 'agent.rate_changed',
            description: __('Commission set to :rate% for :name.', ['rate' => $data['commission_rate'], 'name' => $user->displayName()]),
            target: $user,
            changes: ['commission_rate' => ['old' => $oldRate, 'new' => $data['commission_rate']], 'game_id' => $data['game_id']],
        );

        return back()->with('success', __('Commission set to :rate% for :name.', ['rate' => $data['commission_rate'], 'name' => $user->displayName()]));
    }
}
