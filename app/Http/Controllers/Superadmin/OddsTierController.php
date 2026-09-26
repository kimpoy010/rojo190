<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\OddsTier;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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
        $oddsTiers = OddsTier::orderBy('display_order')->orderBy('label')->get();

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
            // New tiers land at the end of the list, not mixed into the
            // middle of whatever order was already arranged.
            'display_order' => (int) (OddsTier::max('display_order') ?? -1) + 1,
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

    /**
     * Persist a drag-and-drop reorder of the odds tier list (see the
     * index view's handle drag script) — drives both this admin page's
     * own order and the fallback tier list any CombinedSabong event
     * without a custom event_odds_tiers assignment uses.
     */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:odds_tiers,id',
        ]);

        foreach (array_values($data['ids']) as $order => $id) {
            OddsTier::where('id', $id)->update(['display_order' => $order]);
        }

        AuditLogger::log(
            action: 'odds_tier.reordered',
            description: __('Odds tiers reordered.'),
        );

        return response()->json(['success' => true]);
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
