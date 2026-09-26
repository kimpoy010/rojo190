<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Rules\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(Request $request): View
    {
        $refCode = $request->query('ref');
        $referrer = $refCode ? User::where('referral_code', $refCode)->first() : null;

        return view('auth.register', [
            'refCode' => $refCode,
            'refRole' => $referrer?->getRoleNames()->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'ref_code' => 'nullable|string',
            'cf-turnstile-response' => Turnstile::rules(),
        ]);

        // An optional referral link determines who's being registered:
        //   - referred by a superadmin  → new top-level agent
        //   - referred by an agent      → new player recruited under that agent
        //   - no (or unrecognised) ref  → an ordinary, unaffiliated player
        $referrer = ! empty($data['ref_code']) ? User::where('referral_code', $data['ref_code'])->first() : null;
        $isAgentRef = $referrer?->hasRole('agent') ?? false;
        $isSuperadminRef = $referrer?->hasRole('superadmin') ?? false;

        $user = DB::transaction(function () use ($data, $referrer, $isAgentRef, $isSuperadminRef) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'agent_id' => $isAgentRef ? $referrer->id : null,
                'referral_code' => $isSuperadminRef ? Str::upper(Str::random(8)) : null,
            ]);

            $user->assignRole($isSuperadminRef ? 'agent' : 'player');

            Wallet::create(['user_id' => $user->id]);

            return $user;
        });

        Auth::login($user);

        return redirect()->route($user->homeRouteName());
    }
}
