<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Game;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $events = Event::orderByDesc('id')->limit(10)->get();
        $game = Game::where('game_name', 'pool-sabong')->first();

        return view('superadmin.dashboard', compact('events', 'game'));
    }
}
