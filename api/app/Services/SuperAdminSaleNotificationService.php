<?php

namespace App\Services;

use App\Models\CommunicationSettings;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\AdministrativeSaleCompletedNotification;
use App\Notifications\NewSaleNotification;
use App\Notifications\SaleCompletedNotification;
use Illuminate\Support\Facades\Notification;

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

    public function notifyPosSale(Sale $sale): void
    {
        $this->notifySale($sale);

        $settings = CommunicationSettings::current();

        if (! $settings->sale_notifications_enabled) {
            return;
        }

        $emails = [
            ...$settings->getEmailsArray(),
            config('inventory.approval_email'),
        ];

        $recipients = array_values(array_unique(array_filter(
            array_map(fn ($email) => trim((string) $email), $emails),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        foreach ($recipients as $email) {
            Notification::route('mail', $email)->notify(new NewSaleNotification($sale));
        }
    }

    private function superAdmins()
    {
        return User::role('super_admin')->get();
    }
}
