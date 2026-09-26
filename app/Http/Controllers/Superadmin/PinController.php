<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A superadmin's own approval PIN — typed in person to authorize sensitive
 * teller-counter actions (currently: voiding a ticket). See AdminPinService.
 */
class PinController extends Controller
{
    public function edit(): View
    {
        return view('superadmin.pin.edit');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => 'required|digits_between:4,6|confirmed',
            'current_pin' => 'nullable|string',
        ]);

        $admin = $request->user();

        if ($admin->hasPin() && ! Hash::check($request->input('current_pin', ''), $admin->pin)) {
            return back()->with('error', __('Current PIN is incorrect.'));
        }

        $admin->update(['pin' => $data['pin']]);

        AuditLogger::log(
            action: 'settings.pin_updated',
            description: __(':name updated their approval PIN.', ['name' => $admin->displayName()]),
            target: $admin,
        );

        return back()->with('success', __('Approval PIN updated.'));
    }
}
