<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Cockpit;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The arena's cockpits (rings) — each has its own video feed, assigned by
 * the declarator to a fight before opening it for betting (see
 * Declarator\FightController::open()).
 */
class CockpitController extends Controller
{
    public function index(): View
    {
        $cockpits = Cockpit::orderBy('id')->get();

        return view('superadmin.cockpits.index', compact('cockpits'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $cockpit = Cockpit::create($data);

        AuditLogger::log(action: 'cockpit.created', description: __(':name added.', ['name' => $cockpit->name]), target: $cockpit);

        return redirect()->route('superadmin.cockpits.index')->with('success', __('Cockpit added.'));
    }

    public function update(Request $request, Cockpit $cockpit): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stream_url' => 'nullable|string|max:255',
        ]);

        $before = $cockpit->only(['name', 'stream_url']);
        $cockpit->update($data);

        $changes = [];
        foreach ($before as $field => $oldValue) {
            if ($oldValue !== $data[$field]) {
                $changes[$field] = ['old' => $oldValue, 'new' => $data[$field]];
            }
        }

        AuditLogger::log(action: 'cockpit.updated', description: __(':name updated.', ['name' => $cockpit->name]), target: $cockpit, changes: $changes);

        return redirect()->route('superadmin.cockpits.index')->with('success', __(':name updated.', ['name' => $cockpit->name]));
    }

    public function destroy(Cockpit $cockpit): RedirectResponse
    {
        $name = $cockpit->name;
        AuditLogger::log(action: 'cockpit.deleted', description: __(':name removed.', ['name' => $name]), target: $cockpit);

        $cockpit->delete();

        return redirect()->route('superadmin.cockpits.index')->with('success', __('Cockpit removed.'));
    }
}
