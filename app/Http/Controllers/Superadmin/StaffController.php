<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Tellers and declarators — the two counter/ring-side staff roles, grouped
 * on one page since they're otherwise identical accounts (just a different
 * role assigned). Player accounts self-register; agents have their own
 * dedicated section (AgentController) for the extra commission/upline
 * fields neither of these roles needs.
 */
class StaffController extends Controller
{
    private const ROLES = ['teller', 'declarator'];

    public function index(): View
    {
        $staff = User::role(self::ROLES)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get();

        return view('superadmin.staff.index', compact('staff'));
    }

    public function create(): View
    {
        return view('superadmin.staff.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
            ]);
            $user->assignRole($data['role']);

            Wallet::create(['user_id' => $user->id]);

            return $user;
        });

        AuditLogger::log(
            action: 'staff.created',
            description: __(':role account :username created.', ['role' => __(ucfirst($data['role'])), 'username' => $user->username]),
            target: $user,
            changes: ['role' => ['old' => null, 'new' => $data['role']]],
        );

        return redirect()->route('superadmin.staff.index')
            ->with('success', __(':role account :username created.', ['role' => __(ucfirst($data['role'])), 'username' => $user->username]));
    }

    public function edit(User $user): View
    {
        $this->assertIsStaff($user);

        return view('superadmin.staff.edit', ['staffMember' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->assertIsStaff($user);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => ['required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $before = $user->only(['name', 'username', 'email']);
        $oldRole = $user->hasRole('teller') ? 'teller' : 'declarator';

        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            ...(filled($data['password'] ?? null) ? ['password' => bcrypt($data['password'])] : []),
        ]);

        $user->syncRoles([$data['role']]);

        $changes = [];
        foreach (['name', 'username', 'email'] as $field) {
            if ($before[$field] !== $data[$field]) {
                $changes[$field] = ['old' => $before[$field], 'new' => $data[$field]];
            }
        }
        if ($oldRole !== $data['role']) {
            $changes['role'] = ['old' => $oldRole, 'new' => $data['role']];
        }
        if (filled($data['password'] ?? null)) {
            $changes['password'] = ['old' => '(hidden)', 'new' => '(hidden)'];
        }

        AuditLogger::log(
            action: 'staff.updated',
            description: __(':username updated.', ['username' => $user->username]),
            target: $user,
            changes: $changes,
        );

        return redirect()->route('superadmin.staff.index')
            ->with('success', __(':username updated.', ['username' => $user->username]));
    }

    public function toggleStatus(User $user): RedirectResponse
    {
        $this->assertIsStaff($user);

        $oldStatus = $user->status;
        $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        AuditLogger::log(
            action: $newStatus === 'active' ? 'staff.reactivated' : 'staff.deactivated',
            description: $newStatus === 'active'
                ? __(':username reactivated.', ['username' => $user->username])
                : __(':username deactivated.', ['username' => $user->username]),
            target: $user,
            changes: ['status' => ['old' => $oldStatus, 'new' => $newStatus]],
        );

        return redirect()->route('superadmin.staff.index')->with(
            'success',
            $newStatus === 'active'
                ? __(':username reactivated.', ['username' => $user->username])
                : __(':username deactivated.', ['username' => $user->username])
        );
    }

    /**
     * Route-model-bound {user} could be ANY user id — a player, agent, or
     * even another superadmin — so every action here must confirm the
     * target is actually one of the two roles this controller manages
     * before touching it.
     */
    private function assertIsStaff(User $user): void
    {
        abort_if(! $user->hasAnyRole(self::ROLES), 422, 'Target user is not a teller or declarator.');
    }
}
