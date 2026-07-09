<?php

namespace App\Policies;

use App\Models\SubscriptionPlan;
use App\Models\User;

class SubscriptionPlanPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, SubscriptionPlan $plan): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, SubscriptionPlan $plan): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, SubscriptionPlan $plan): bool
    {
        return $user->hasRole('super_admin');
    }
}
