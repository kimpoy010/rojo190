<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AgentDashboardDownlinePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_downline_players_are_paginated_instead_of_loading_every_recruit(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'agent']);

        $agent = User::factory()->create();
        $agent->assignRole('agent');
        Wallet::create(['user_id' => $agent->id]);

        // One more than a page's worth, so a second page must exist.
        User::factory()->count(16)->create(['agent_id' => $agent->id])->each(function (User $player) {
            $player->assignRole('player');
            Wallet::create(['user_id' => $player->id]);
        });

        $response = $this->actingAs($agent)->get(route('agent.dashboard'));

        $response->assertOk();
        $response->assertViewHas('downlinePlayers', function ($downlinePlayers) {
            return $downlinePlayers->count() === 15 && $downlinePlayers->total() === 16 && $downlinePlayers->hasMorePages();
        });
        $response->assertSee('Downline players (16)');
    }
}
