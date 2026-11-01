<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_description' => $this->item_description,
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'tax' => $this->tax,
            'total_price' => $this->total_price,
            'cost_price' => $this->cost_price,
            'selling_price' => $this->selling_price,
            'profit_amount' => $this->profit_amount,

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),
            'subscription_plan' => new SubscriptionPlanResource($this->whenLoaded('subscriptionPlan')),

            // Timestamps
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
