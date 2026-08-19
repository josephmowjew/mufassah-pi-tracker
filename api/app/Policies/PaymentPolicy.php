<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        if ($user->hasAnyRole(['store_admin', 'customer_service'])) {
            return $payment->user && $user->canAccessBranch($payment->user->branch_id);
        }

        return $user->id === $payment->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin')
                && $payment->user
                && $user->canAccessBranch($payment->user->branch_id));
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasRole('super_admin');
    }
}
