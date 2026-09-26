<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $balancerSwitch = Setting::get('balancer_switch', 'off');

        return view('superadmin.settings.edit', compact('balancerSwitch'));
    }

    public function update(Request $request): RedirectResponse
    {
        $old = Setting::get('balancer_switch', 'off');
        $new = $request->boolean('balancer_switch') ? 'on' : 'off';
        Setting::set('balancer_switch', $new);

        AuditLogger::log(
            action: 'settings.updated',
            description: __('Payout balancer switched :new.', ['new' => $new]),
            changes: ['balancer_switch' => ['old' => $old, 'new' => $new]],
        );

        return redirect()->route('superadmin.settings.edit')->with('success', __('Settings updated.'));
    }
}
