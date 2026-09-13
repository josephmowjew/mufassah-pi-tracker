<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the actor may administer the target account.
     *
     * Branch scope is enforced separately by the controller (see
     * AdminUserController::authorizeUserBranch). This rule is about rank, and
     * it lives in a policy so every path that administers an account inherits
     * it rather than each controller method remembering to check.
     *
     * Without it the role hierarchy was only a UI convention: a store admin
     * sharing a branch with another admin (or a super admin) could change that
     * account's email and password and then take it over.
     *
     * An actor may always manage their own account; individual actions add
     * their own self-guards (self-suspension, self-deletion) on top, which is
     * why this returns true for self rather than false.
     */
    public function manage(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $actor->outranks($target);
    }
}
