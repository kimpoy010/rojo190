<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\OddsTier;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CombinedSabong's meron:wala matching ratios (e.g. "10-9") — global,
 * assigned per-event via Superadmin\EventController's odds_tier_ids field.
 */
class OddsTierController extends Controller
{
    public function index(): View
    {
        $oddsTiers = OddsTier::orderBy('label')->get();

        return view('superadmin.odds-tiers.index', compact('oddsTiers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:20',
            'meron_ratio' => 'required|numeric|min:0.01',
            'wala_ratio' => 'required|numeric|min:0.01',
        ]);

        $tier = OddsTier::create([
            ...$data,
            'is_active' => true,
        ]);

        AuditLogger::log(
            action: 'odds_tier.created',
            description: __('Odds tier :label created.', ['label' => $tier->label]),
            target: $tier,
        );

        return redirect()->route('superadmin.odds-tiers.index')->with('success', __('Odds tier added.'));
    }

    public function update(Request $request, OddsTier $oddsTier): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:20',
            'meron_ratio' => 'required|numeric|min:0.01',
            'wala_ratio' => 'required|numeric|min:0.01',
            'is_active' => 'sometimes|boolean',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $oddsTier->update($data);

        AuditLogger::log(
            action: 'odds_tier.updated',
            description: __('Odds tier :label updated.', ['label' => $oddsTier->label]),
            target: $oddsTier,
        );

        return redirect()->route('superadmin.odds-tiers.index')->with('success', __('Odds tier updated.'));
    }

    public function destroy(OddsTier $oddsTier): RedirectResponse
    {
        $label = $oddsTier->label;
        $oddsTier->delete();

        AuditLogger::log(
            action: 'odds_tier.deleted',
            description: __('Odds tier :label deleted.', ['label' => $label]),
        );

        return redirect()->route('superadmin.odds-tiers.index')->with('success', __('Odds tier deleted.'));
    }
}
