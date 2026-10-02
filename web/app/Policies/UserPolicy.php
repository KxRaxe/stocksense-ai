<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function create(User $actor): bool
    {
        return $this->canManage($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManage($actor);
    }

    /**
     * Owners cannot change their own role, so they cannot lock themselves out
     * of administration by accident.
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $this->canManage($actor) && $actor->isNot($target);
    }

    /**
     * Covers both deactivating and re-activating. Owners cannot deactivate
     * themselves.
     */
    public function setActive(User $actor, User $target): bool
    {
        return $this->canManage($actor) && $actor->isNot($target);
    }

    public function sendSetupLink(User $actor, User $target): bool
    {
        return $this->canManage($actor) && $target->is_active;
    }

    private function canManage(User $actor): bool
    {
        return $actor->hasPermissionTo(Permission::ManageUsers);
    }
}
