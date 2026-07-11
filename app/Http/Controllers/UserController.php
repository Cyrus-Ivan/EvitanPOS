<?php

namespace App\Http\Controllers;

use App\Http\Requests\Users\ResetUserPasswordRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRoleRequest;
use App\Models\User;
use App\Support\Auth\SessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee management (Phase 13). Every write is authorized against UserPolicy,
 * which enforces the Owner > Admin > Cashier hierarchy: an actor may only act on
 * users they strictly outrank, and may only assign roles below their own.
 */
class UserController extends Controller
{
    /**
     * Paginated employee list with search and an active/archived/all filter.
     * Archived (soft-deleted) users remain visible via the filter, per the
     * "never delete business records" invariant.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', '')) ?: null;
        $status = $this->normalizeStatus($request->query('status'));

        $users = User::query()
            ->when($status === 'active', fn ($query) => $query->whereNull('deleted_at'))
            ->when($status === 'archived', fn ($query) => $query->onlyTrashed())
            ->when($status === 'all', fn ($query) => $query->withTrashed())
            ->when($search, fn ($query) => $query->whereLikeAny(['name', 'username'], $search))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'role' => $user->role,
                'deleted_at' => $user->deleted_at,
                'created_at' => $user->created_at,
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'assignableRoles' => $this->assignableRoles($request->user()),
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'role' => $data['role'],
            'password' => Hash::make($data['password']),
        ]);

        // TODO(Phase 15): audit user.created

        return to_route('users')->with('toast', [
            'type' => 'success',
            'message' => 'User created.',
        ]);
    }

    /**
     * Change an employee's role. The name is immutable, so only the role moves.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $user->update(['role' => $request->validated()['role']]);

        // TODO(Phase 15): audit user.role_changed

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Role updated.',
        ]);
    }

    /**
     * Administratively reset an employee's password, then force-log-out their
     * active session so they must sign in again with the new credentials.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user, SessionManager $sessions): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $user->update(['password' => Hash::make($request->validated()['password'])]);

        $sessions->forceLogout($user, 'Password reset by an administrator.');

        // TODO(Phase 15): audit user.password_reset

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Password reset. The user has been signed out.',
        ]);
    }

    /**
     * Archive (soft delete) an employee. The Owner is protected by both the
     * policy (nothing outranks it) and the model-layer delete guard.
     */
    public function archive(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $user->delete();

        // TODO(Phase 15): audit user.archived

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'User archived.',
        ]);
    }

    /**
     * Restore an archived employee. Bound with trashed models via the route.
     */
    public function restore(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $user->restore();

        // TODO(Phase 15): audit user.restored

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'User restored.',
        ]);
    }

    /**
     * The role string values the actor may assign — surfaced to the create/role
     * dialogs so the client offers only permitted choices.
     *
     * @return list<string>
     */
    private function assignableRoles(User $actor): array
    {
        return array_map(fn ($role) => $role->value, $actor->role->assignableRoles());
    }

    /**
     * Constrain the status filter to a known tri-state, defaulting to active.
     */
    private function normalizeStatus(mixed $status): string
    {
        return in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active';
    }
}
