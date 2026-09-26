<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RfidReader;
use App\Models\RfidTerminal;
use App\Services\RfidScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RfidScanController extends Controller
{
    public function __construct(private RfidScanService $scanService) {}

    /**
     * Called directly by an ESP32 board over WiFi every time a card is
     * tapped. Every board runs identical firmware and only ever reports
     * its own device_id (WiFi MAC) — never a role — so a device that's
     * never been seen before is auto-registered here as unassigned rather
     * than rejected outright, and a superadmin assigns its role
     * afterward. This also means a compromised/misconfigured board can't
     * simply claim to be "meron" — the role is looked up server-side.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => 'required|string|max:64',
            'tag_uid' => 'required|string|max:64',
        ]);

        /** @var RfidTerminal $terminal */
        $terminal = $request->attributes->get('rfidTerminal');
        $deviceId = trim($data['device_id']);

        // Keyed purely by device_id (not also terminal) so that moving a
        // board's config to point at a different terminal re-parents it
        // here too, instead of leaving it listed under a stale terminal.
        $reader = RfidReader::firstOrNew(['device_id' => $deviceId]);
        $reader->rfid_terminal_id = $terminal->id;
        $reader->last_seen_at = now();
        $reader->save();

        if (! $reader->isAssigned()) {
            return response()->json([
                'success' => false,
                'message' => __('This reader has not been assigned a role yet. Ask a superadmin to assign it under RFID Terminals.'),
                'details' => [],
            ], 422);
        }

        $result = $this->scanService->handle($terminal, $reader->role, trim($data['tag_uid']));

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
