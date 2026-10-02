<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SetUpAccountNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User administration for the Owner: create, edit, activate and deactivate
 * accounts, and send password links. Every change is written to the audit log
 * with the acting user as the causer (none for the first Owner, created from
 * the command line).
 */
class UserManager
{
    public function create(string $name, string $email, Role $role, ?User $actor = null): User
    {
        return DB::transaction(function () use ($name, $email, $role, $actor) {
            $user = new User([
                'name' => $name,
                'email' => $email,
                // Nobody knows this password; the person sets their own through
                // the link in the set-up email.
                'password' => Str::password(40),
            ]);

            // The set-up link is mailed to this address, so only its owner can
            // finish creating the account. That already proves they control the
            // mailbox, so a separate verification email would be redundant.
            $user->email_verified_at = now();
            $user->save();

            $user->assignRole($role->value);

            $this->audit($user, $actor, 'role_assigned', "Role set to {$role->label()}", [
                'role' => $role->value,
            ]);

            return $user;
        });
    }

    public function update(User $user, string $name, string $email, Role $role, User $actor): User
    {
        $emailChanged = $email !== $user->email;

        DB::transaction(function () use ($user, $name, $email, $role, $actor, $emailChanged) {
            $user->fill(['name' => $name, 'email' => $email]);

            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            $user->save();

            $current = $user->currentRole();

            if ($current !== $role) {
                if ($current === Role::Owner) {
                    $this->ensureAnotherActiveOwner($user);
                }

                $user->syncRoles($role->value);

                $this->audit($user, $actor, 'role_changed', "Role changed to {$role->label()}", [
                    'from' => $current?->value,
                    'to' => $role->value,
                ]);
            }
        });

        // The address has changed, so it needs confirming before it is trusted.
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    public function setActive(User $user, bool $active, User $actor): void
    {
        DB::transaction(function () use ($user, $active, $actor) {
            if (! $active && $user->currentRole() === Role::Owner) {
                $this->ensureAnotherActiveOwner($user);
            }

            $user->forceFill(['is_active' => $active]);

            if (! $active) {
                // End any "remember me" cookie and open sessions straight away.
                $user->forceFill(['remember_token' => null]);
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }

            $user->save();

            $this->audit(
                $user,
                $actor,
                $active ? 'activated' : 'deactivated',
                $active ? 'Account activated' : 'Account deactivated',
            );
        });
    }

    public function sendSetupLink(User $user, ?User $actor = null): void
    {
        $user->notify(new SetUpAccountNotification(Password::broker()->createToken($user)));

        $this->audit($user, $actor, 'setup_link_sent', 'Password set-up link sent');
    }

    /**
     * The application must never be left without an active Owner. Owners cannot
     * change their own role or deactivate themselves, so this only matters when
     * two Owners act on each other at the same moment; the row lock makes the
     * second request see the first one's result.
     */
    private function ensureAnotherActiveOwner(User $user): void
    {
        $others = User::role(Role::Owner->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->get(['users.id']);

        if ($others->isEmpty()) {
            throw ValidationException::withMessages([
                'user' => 'At least one active Owner is required.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(User $user, ?User $actor, string $event, string $description, array $properties = []): void
    {
        activity('users')
            ->performedOn($user)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($description);
    }
}
