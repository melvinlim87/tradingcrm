<?php

namespace App\Http\Controllers;

use App\Models\Mt5Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canManageUsers(), 403, 'You do not have access to user management.');

        // Optional role filter via query string (used by /admins which forces role=admin)
        $roleFilter = $request->query('role');

        $usersQuery = $viewer->visibleUsersQuery()
            ->with([
                'creator:id,name,email,role',
                'assignedAccounts:id,account_number,account_name',
            ]);

        if ($roleFilter && in_array($roleFilter, ['administrator', 'admin', 'user'], true)) {
            $usersQuery->where('role', $roleFilter);
        }

        $users = $usersQuery
            ->orderBy('role')
            ->orderBy('name')
            ->get([
                'id', 'name', 'email', 'role', 'created_by', 'created_at',
            ]);

        // Accounts the current viewer is allowed to assign
        $assignableAccounts = $viewer->visibleAccountsQuery()
            ->orderBy('account_number')
            ->get(['id', 'account_number', 'account_name', 'broker']);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'assignable_accounts' => $assignableAccounts,
            'assignable_roles'    => $viewer->assignableRoles(),
            'viewer_role'         => $viewer->role,
            'viewer_id'           => $viewer->id,
            'role_filter'         => $roleFilter,
        ]);
    }

    /**
     * Administrator-only dedicated page for managing admin-role users.
     * Reuses the same Users/Index Vue page but pre-filters role=admin and
     * locks the assignable_roles dropdown to ['admin'].
     */
    public function admins(Request $request): InertiaResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403, 'Only administrators can manage admins.');

        $admins = User::query()
            ->where('role', 'admin')
            ->with(['creator:id,name,email,role'])
            ->withCount('createdAccounts', 'managedUsers')
            ->orderBy('name')
            ->get([
                'id', 'name', 'email', 'role', 'created_by', 'created_at',
            ]);

        return Inertia::render('Admins/Index', [
            'admins'      => $admins,
            'viewer_id'   => $viewer->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canManageUsers(), 403);

        $data = $this->validateUser($request, $viewer, null);

        DB::transaction(function () use ($viewer, $data) {
            $user = User::create([
                'name'       => $data['name'],
                'email'      => $data['email'],
                'password'   => Hash::make($data['password']),
                'role'       => $data['role'],
                'created_by' => $viewer->id,
                'email_verified_at' => now(),
            ]);

            $this->syncAssignedAccounts($user, $viewer, $data['assigned_account_ids'] ?? []);
        });

        return redirect()->route('users.index')->with('success', "User {$data['email']} created.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canManageUser($user), 403, 'You cannot edit this user.');

        $data = $this->validateUser($request, $viewer, $user);

        DB::transaction(function () use ($viewer, $user, $data) {
            $updates = [
                'name'  => $data['name'],
                'email' => $data['email'],
                'role'  => $data['role'],
            ];
            if (! empty($data['password'])) {
                $updates['password'] = Hash::make($data['password']);
            }
            $user->update($updates);

            $this->syncAssignedAccounts($user, $viewer, $data['assigned_account_ids'] ?? []);
        });

        return redirect()->route('users.index')->with('success', "User {$user->email} updated.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->canManageUser($user), 403);

        $email = $user->email;
        $user->delete();

        return redirect()->route('users.index')->with('success', "User {$email} deleted.");
    }

    private function validateUser(Request $request, User $viewer, ?User $target): array
    {
        $emailRule = ['required', 'email', 'max:191'];
        if ($target) {
            $emailRule[] = "unique:users,email,{$target->id}";
        } else {
            $emailRule[] = 'unique:users,email';
        }

        $rules = [
            'name'  => 'required|string|max:191',
            'email' => $emailRule,
            'role'  => ['required', 'in:' . implode(',', $viewer->assignableRoles())],
            'assigned_account_ids'   => 'array',
            'assigned_account_ids.*' => 'integer|exists:mt5_accounts,id',
        ];

        $rules['password'] = $target
            ? 'nullable|string|min:8'
            : 'required|string|min:8';

        return $request->validate($rules);
    }

    /**
     * Sync the user's assigned accounts — but only allow the viewer to attach
     * accounts they themselves can see (visibleAccountsQuery).
     */
    private function syncAssignedAccounts(User $user, User $viewer, array $ids): void
    {
        if ($user->role !== User::ROLE_USER) {
            // Only the 'user' role uses the explicit-assignment pivot.
            $user->assignedAccounts()->sync([]);
            return;
        }

        $allowedIds = $viewer->visibleAccountsQuery()->pluck('id')->all();
        $filtered = array_intersect($ids, $allowedIds);
        $user->assignedAccounts()->sync($filtered);
    }
}
