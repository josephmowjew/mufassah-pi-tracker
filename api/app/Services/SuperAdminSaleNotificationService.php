<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\AdministrativeSaleCompletedNotification;
use App\Notifications\SaleCompletedNotification;

class SuperAdminSaleNotificationService
{
    public function notifySale(Sale $sale): void
    {
        $this->superAdmins()->each(
            fn (User $superAdmin) => $superAdmin->notify(new SaleCompletedNotification($sale))
        );
    }

    public function notifyAdministrativeSale(InventoryMovement $movement): void
    {
        $this->superAdmins()->each(
            fn (User $superAdmin) => $superAdmin->notify(new AdministrativeSaleCompletedNotification($movement))
        );
    }

    private function superAdmins()
    {
        return User::role('super_admin')->get();
    }
}
