<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['store_admin', 'customer_service'])) {
            return $subscription->user && $user->canAccessBranch($subscription->user->branch_id);
        }

        return $user->id === $subscription->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Subscription $subscription): bool
    {
        if ($user->hasRole('super_admin')
            || ($user->hasRole('store_admin')
                && $subscription->user
                && $user->canAccessBranch($subscription->user->branch_id))) {
            return true;
        }

        return $user->id === $subscription->user_id;
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        if ($user->hasRole('super_admin')
            || ($user->hasRole('store_admin')
                && $subscription->user
                && $user->canAccessBranch($subscription->user->branch_id))) {
            return true;
        }

        return $user->id === $subscription->user_id;
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->hasRole('super_admin');
    }

    public function suspend(User $user, Subscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    public function reactivate(User $user, Subscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }
}
