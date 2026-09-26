<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class ProfileController extends Controller
{
    /**
     * The player's "profile QR" — shown to a teller so they can identify
     * the player instantly (e.g. when linking an RFID card) without typing
     * or searching a username.
     */
    public function show(): View
    {
        $player = auth()->user();
        $player->profileCode(); // ensure it exists before rendering the QR

        return view('player.profile.show', ['player' => $player]);
    }
}
