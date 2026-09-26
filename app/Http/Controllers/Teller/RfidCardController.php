<?php

namespace App\Http\Controllers\Teller;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ScannedCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RfidCardController extends Controller
{
    public function index(): View
    {
        $assigned = User::role('player')
            ->whereNotNull('rfid_uid')
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return view('teller.rfid.index', compact('assigned'));
    }

    /**
     * Live username suggestions for the "link a card" form's autocomplete.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $players = User::role('player')
            ->where('username', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%')
            ->orderBy('username')
            ->limit(8)
            ->get(['id', 'username', 'name', 'rfid_uid']);

        return response()->json($players->map(fn (User $player) => [
            'username' => $player->username,
            'name' => $player->name,
            'has_card' => ! is_null($player->rfid_uid),
        ]));
    }

    /**
     * Landing page after a teller scans a player's profile QR (or types
     * their code in as a manual fallback) — identifies the player without
     * needing the username search at all.
     */
    public function linkForm(User $user): View
    {
        abort_unless($user->hasRole('player'), 404);

        return view('teller.rfid.link', ['player' => $user]);
    }

    /**
     * Manual fallback for the profile QR flow when a camera isn't
     * available — the teller types/pastes the code shown under the QR.
     */
    public function lookupCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string']);

        $raw = ScannedCode::extract($data['code']);

        $player = User::role('player')->where('player_code', strtoupper($raw))->first();

        if (! $player) {
            return redirect()->route('teller.rfid.index')->with('error', __('No player found for that code.'));
        }

        return redirect()->route('teller.rfid.link', $player);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required_without:player_code|nullable|string',
            'player_code' => 'required_without:username|nullable|string',
            'tag_uid' => 'required|string|max:64',
        ]);

        if (! empty($data['player_code'])) {
            $player = User::role('player')->where('player_code', $data['player_code'])->first();
            $notFoundMessage = __('That player QR/code is no longer valid.');
        } else {
            $player = User::role('player')->where('username', $data['username'])->first();
            $notFoundMessage = __('No player found with username ":username".', ['username' => $data['username']]);
        }

        if (! $player) {
            return back()->with('error', $notFoundMessage)->withInput();
        }

        $tagUid = trim($data['tag_uid']);
        $holder = User::where('rfid_uid', $tagUid)->where('id', '!=', $player->id)->first();

        if ($holder) {
            return back()->with('error', __('This card is already assigned to :name. Unassign it first.', ['name' => $holder->displayName()]))->withInput();
        }

        $player->update(['rfid_uid' => $tagUid]);

        return redirect()->route('teller.rfid.index')->with('success', __('Card linked to :name.', ['name' => $player->displayName()]));
    }

    public function destroy(User $user): RedirectResponse
    {
        $user->update(['rfid_uid' => null]);

        return redirect()->route('teller.rfid.index')->with('success', __('Card unlinked from :name.', ['name' => $user->displayName()]));
    }
}
