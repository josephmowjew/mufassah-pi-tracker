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
        if ($user->hasRole(['super_admin', 'store_admin', 'customer_service'])) {
            return true;
        }

        return $user->id === $payment->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasRole('super_admin');
    }
}
