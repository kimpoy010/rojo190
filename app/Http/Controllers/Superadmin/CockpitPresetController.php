<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Cockpit;
use App\Models\CockpitPreset;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A curated group of Cockpits, assigned to an Event (see
 * Superadmin\EventController) instead of a raw stream URL — see
 * CockpitPreset's own docblock for why this exists alongside the
 * per-fight Cockpit assignment.
 */
class CockpitPresetController extends Controller
{
    public function index(): View
    {
        $presets = CockpitPreset::with('cockpits')->orderBy('id')->get();
        $cockpits = Cockpit::orderBy('name')->get();

        return view('superadmin.cockpit-presets.index', compact('presets', 'cockpits'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'cockpit_ids' => 'array',
            'cockpit_ids.*' => 'integer|exists:cockpits,id',
        ]);

        $preset = CockpitPreset::create(['name' => $data['name']]);
        $this->syncCockpits($preset, $data['cockpit_ids'] ?? []);

        AuditLogger::log(action: 'cockpit_preset.created', description: __('Preset :name added.', ['name' => $preset->name]), target: $preset);

        return redirect()->route('superadmin.cockpit-presets.index')->with('success', __('Preset added.'));
    }

    public function update(Request $request, CockpitPreset $cockpitPreset): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'cockpit_ids' => 'array',
            'cockpit_ids.*' => 'integer|exists:cockpits,id',
        ]);

        $oldName = $cockpitPreset->name;
        $oldCockpitIds = $cockpitPreset->cockpits()->pluck('cockpits.id')->all();

        $cockpitPreset->update(['name' => $data['name']]);
        $this->syncCockpits($cockpitPreset, $data['cockpit_ids'] ?? []);

        AuditLogger::log(
            action: 'cockpit_preset.updated',
            description: __('Preset :name updated.', ['name' => $cockpitPreset->name]),
            target: $cockpitPreset,
            changes: ['name' => ['old' => $oldName, 'new' => $data['name']], 'cockpit_ids' => ['old' => $oldCockpitIds, 'new' => $data['cockpit_ids'] ?? []]],
        );

        return redirect()->route('superadmin.cockpit-presets.index')->with('success', __(':name updated.', ['name' => $cockpitPreset->name]));
    }

    public function destroy(CockpitPreset $cockpitPreset): RedirectResponse
    {
        AuditLogger::log(action: 'cockpit_preset.deleted', description: __('Preset :name removed.', ['name' => $cockpitPreset->name]), target: $cockpitPreset);

        $cockpitPreset->delete();

        return redirect()->route('superadmin.cockpit-presets.index')->with('success', __('Preset removed.'));
    }

    /**
     * Attaches the picked cockpits with a position matching the order the
     * form submitted them in — position decides which one is the event's
     * "primary" cockpit later (see Event::primaryStreamUrl()).
     *
     * @param  array<int, int>  $cockpitIds
     */
    private function syncCockpits(CockpitPreset $preset, array $cockpitIds): void
    {
        $preset->cockpits()->sync(
            collect($cockpitIds)->values()->mapWithKeys(fn (int $id, int $position) => [
                $id => ['position' => $position],
            ])
        );
    }
}
