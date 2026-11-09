<?php

namespace App\Notifications;

use App\Models\Sale;
use Illuminate\Notifications\Notification;

class SaleCompletedNotification extends Notification
{
    public function __construct(public readonly Sale $sale) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->sale->loadMissing(['branch', 'soldBy']);
        $branchName = $this->sale->branch?->name ?? 'Unknown branch';
        $sellerName = $this->sale->soldBy?->name ?? 'Unknown seller';
        $amount = round((float) $this->sale->total_amount, 2);

        return [
            'type' => 'sale_completed',
            'title' => 'Sale completed',
            'message' => "Sale {$this->sale->sale_number} was completed at {$branchName} for MWK ".number_format($amount, 2).'.',
            'sale_id' => $this->sale->id,
            'sale_number' => $this->sale->sale_number,
            'amount' => $amount,
            'branch_id' => $this->sale->branch_id,
            'branch_name' => $branchName,
            'sold_by_id' => $this->sale->sold_by_id,
            'sold_by_name' => $sellerName,
            'payment_method' => $this->sale->payment_method?->value ?? 'layby',
            'status' => $this->sale->status?->value,
        ];
    }
}
