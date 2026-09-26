<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The system-wide audit trail — every instrumented action across every
 * role (see App\Support\AuditLogger and its call sites), hash-chained per
 * actor the same way the financial ledger is (see App\Models\Concerns\
 * HasHashChain) so the trail itself can't be quietly edited after the
 * fact.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View|Response
    {
        $data = $request->validate([
            'username' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:255',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $username = $data['username'] ?? null;
        $action = $data['action'] ?? null;

        $logs = AuditLog::query()
            ->when($username, fn ($q) => $q->where('actor_name', 'like', "%{$username}%"))
            ->when($action, fn ($q) => $q->where('action', $action))
            ->when($data['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        if ($request->ajax()) {
            return response()->view('superadmin.audit._results', compact('logs'));
        }

        return view('superadmin.audit.index', [
            'logs' => $logs,
            'username' => $username,
            'action' => $action,
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
            'actions' => $actions,
        ]);
    }
}
