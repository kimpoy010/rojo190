<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\RfidReader;
use App\Models\RfidTerminal;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RfidTerminalController extends Controller
{
    public function index(): View
    {
        $terminals = RfidTerminal::with(['readers' => fn ($q) => $q->orderBy('device_id')])
            ->orderByDesc('created_at')->get();

        return view('superadmin.rfid-terminals.index', compact('terminals'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $terminal = RfidTerminal::create([
            'name' => $data['name'],
            'token' => RfidTerminal::generateToken(),
        ]);

        AuditLogger::log(action: 'rfid_terminal.created', description: __('Terminal :name created.', ['name' => $terminal->name]), target: $terminal);

        return redirect()->route('superadmin.rfid-terminals.index')->with('success', __('Terminal created.'));
    }

    public function toggle(RfidTerminal $rfidTerminal): RedirectResponse
    {
        $wasActive = $rfidTerminal->is_active;
        $rfidTerminal->update(['is_active' => ! $wasActive]);

        AuditLogger::log(
            action: $rfidTerminal->is_active ? 'rfid_terminal.enabled' : 'rfid_terminal.disabled',
            description: $rfidTerminal->is_active ? __(':name enabled.', ['name' => $rfidTerminal->name]) : __(':name disabled.', ['name' => $rfidTerminal->name]),
            target: $rfidTerminal,
            changes: ['is_active' => ['old' => $wasActive, 'new' => $rfidTerminal->is_active]],
        );

        return back()->with('success', $rfidTerminal->is_active
            ? __(':name enabled.', ['name' => $rfidTerminal->name])
            : __(':name disabled.', ['name' => $rfidTerminal->name]));
    }

    public function regenerateToken(RfidTerminal $rfidTerminal): RedirectResponse
    {
        $rfidTerminal->update(['token' => RfidTerminal::generateToken()]);

        AuditLogger::log(action: 'rfid_terminal.token_regenerated', description: __('Token regenerated for :name.', ['name' => $rfidTerminal->name]), target: $rfidTerminal);

        return back()->with('success', __('Token regenerated for :name. Update the TERMINAL_TOKEN constant on every reader board at this station and re-flash.', ['name' => $rfidTerminal->name]));
    }

    public function destroy(RfidTerminal $rfidTerminal): RedirectResponse
    {
        AuditLogger::log(action: 'rfid_terminal.deleted', description: __('Terminal :name removed.', ['name' => $rfidTerminal->name]), target: $rfidTerminal);

        $rfidTerminal->delete();

        return redirect()->route('superadmin.rfid-terminals.index')->with('success', __('Terminal removed.'));
    }

    /**
     * Assign (or change, or clear) which side a physical reader board
     * counts as. Every board runs identical firmware and only ever
     * reports its own device_id — this mapping is the only place a
     * reader's role is decided, and reassigning it (e.g. swapping which
     * physical pad is MERON vs WALA) is just a dropdown change here, no
     * re-flashing needed.
     */
    public function assignReaderRole(Request $request, RfidTerminal $rfidTerminal, RfidReader $rfidReader): RedirectResponse
    {
        abort_if($rfidReader->rfid_terminal_id !== $rfidTerminal->id, 404);

        $data = $request->validate([
            'role' => ['nullable', Rule::in(['meron', 'wala', 'topup', 'identify', 'balance'])],
            'label' => 'nullable|string|max:100',
        ]);

        $oldRole = $rfidReader->role;
        $rfidReader->update([
            'role' => $data['role'] ?? null,
            'label' => $data['label'] ?? null,
        ]);

        AuditLogger::log(
            action: 'rfid_reader.role_assigned',
            description: __('Reader :id updated.', ['id' => $rfidReader->device_id]),
            target: $rfidReader,
            changes: ['role' => ['old' => $oldRole, 'new' => $data['role'] ?? null]],
        );

        return back()->with('success', __('Reader :id updated.', ['id' => $rfidReader->device_id]));
    }

    public function destroyReader(RfidTerminal $rfidTerminal, RfidReader $rfidReader): RedirectResponse
    {
        abort_if($rfidReader->rfid_terminal_id !== $rfidTerminal->id, 404);

        AuditLogger::log(action: 'rfid_reader.deleted', description: __('Reader :id removed.', ['id' => $rfidReader->device_id]), target: $rfidReader);

        $rfidReader->delete();

        return back()->with('success', __('Reader removed.'));
    }
}
