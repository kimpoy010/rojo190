<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Account settings shared across every role (unlike the role-scoped
 * controllers elsewhere) — a player reaches this from their Profile page,
 * every other role from the header's user menu.
 */
class AccountController extends Controller
{
    public function editPassword(): View
    {
        return view('account.password');
    }

    /**
     * Self-service password change — deliberately doesn't ask for the
     * current password: the session is already proof of who's asking.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        AuditLogger::log(
            action: 'account.password_changed',
            description: __(':name changed their password.', ['name' => $request->user()->displayName()]),
            target: $request->user(),
        );

        return redirect()->route('account.password.edit')->with('success', __('Password updated.'));
    }
}
