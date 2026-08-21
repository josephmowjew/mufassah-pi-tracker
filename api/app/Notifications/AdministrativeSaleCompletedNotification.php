<?php

namespace App\Notifications;

use App\Models\InventoryMovement;
use Illuminate\Notifications\Notification;

class AdministrativeSaleCompletedNotification extends Notification
{
    public function __construct(public readonly InventoryMovement $movement) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->movement->loadMissing(['branch', 'device', 'createdBy']);
        $branchName = $this->movement->branch?->name ?? 'Unknown branch';
        $deviceLabel = trim(($this->movement->device?->brand ?? '').' '.($this->movement->device?->model ?? ''));
        $deviceLabel = $deviceLabel !== '' ? $deviceLabel : "Device #{$this->movement->device_id}";
        $amount = round((float) $this->movement->total_amount, 2);
        $profit = round((float) $this->movement->profit_amount, 2);

        return [
            'type' => 'administrative_sale_completed',
            'title' => 'Administrative sale completed',
            'message' => "Administrative sale for {$deviceLabel} was completed at {$branchName} for MWK ".number_format($amount, 2).'.',
            'movement_id' => $this->movement->id,
            'movement_number' => $this->movement->movement_number,
            'device_id' => $this->movement->device_id,
            'device_label' => $deviceLabel,
            'amount' => $amount,
            'profit_amount' => $profit,
            'branch_id' => $this->movement->branch_id,
            'branch_name' => $branchName,
            'created_by_id' => $this->movement->created_by_id,
            'created_by_name' => $this->movement->createdBy?->name ?? 'Unknown administrator',
            'reason' => $this->movement->reason,
            'status' => 'completed',
        ];
    }
}
