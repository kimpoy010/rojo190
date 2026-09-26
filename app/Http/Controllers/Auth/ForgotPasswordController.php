<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        Password::sendResetLink($data);

        // Deliberately the same message whether or not that email has an
        // account — unlike registration's one-shot "email already taken"
        // check, this form would otherwise be a standing oracle for
        // probing which emails are registered.
        return back()->with('success', __('If that email is registered, a password reset link is on its way.'));
    }
}
