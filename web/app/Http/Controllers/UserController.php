<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use App\Services\Users\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * User administration. Reached only through the `users.manage` permission
 * (see routes/web.php); UserPolicy adds the per-user rules.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserManager $users) {}

    public function index(Request $request): Response
    {
        return Inertia::render('users/index', [
            'users' => User::query()
                ->with('roles')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $this->present($user, $request->user()))
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('users/create', [
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $actor = $request->user();

        $user = $this->users->create(
            $request->validated('name'),
            $request->validated('email'),
            Role::from($request->validated('role')),
            $actor,
        );

        try {
            $this->users->sendSetupLink($user, $actor);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'User created. A set-up email has been sent.']);
        } catch (Throwable $e) {
            report($e);

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => 'User created, but the set-up email could not be sent. Use "Send set-up link" to try again.',
            ]);
        }

        return to_route('users.index');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('users/edit', [
            'user' => $this->present($user->load('roles'), $request->user()),
            'roles' => $this->roleOptions(),
            'can' => [
                'changeRole' => Gate::allows('changeRole', $user),
                'setActive' => Gate::allows('setActive', $user),
                'sendSetupLink' => Gate::allows('sendSetupLink', $user),
            ],
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $role = Role::from($request->validated('role'));

        if ($role !== $user->currentRole()) {
            Gate::authorize('changeRole', $user);
        }

        $this->users->update(
            $user,
            $request->validated('name'),
            $request->validated('email'),
            $role,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User updated.']);

        return to_route('users.index');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('setActive', $user);

        $this->users->setActive($user, false, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User deactivated.']);

        return back();
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('setActive', $user);

        $this->users->setActive($user, true, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User activated.']);

        return back();
    }

    public function sendSetupLink(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('sendSetupLink', $user);

        $this->users->sendSetupLink($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Set-up email sent.']);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user, User $actor): array
    {
        $role = $user->currentRole();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'is_active' => $user->is_active,
            'is_self' => $user->is($actor),
            'email_verified' => $user->email_verified_at !== null,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_map(
            fn (Role $role) => ['value' => $role->value, 'label' => $role->label()],
            Role::cases(),
        );
    }
}
