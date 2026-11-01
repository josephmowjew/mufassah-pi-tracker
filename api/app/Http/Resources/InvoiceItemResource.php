<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCosts = ! $request->routeIs('invoices.public-show')
            && (bool) $request->user()?->can('view-device-costs');

        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'item_description' => $this->item_description,
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'tax_rate' => $this->tax_rate,
            'tax' => $this->tax,
            'total_price' => $this->total_price,
            'selling_price' => $this->selling_price,
            'cost_price' => $this->when($canViewCosts, $this->cost_price),
            'profit_amount' => $this->when($canViewCosts, $this->profit_amount),
            'pricing_source' => $this->when($canViewCosts, $this->pricing_source),
            'product_specs' => $this->product_specs,
            'device' => new DeviceResource($this->whenLoaded('device')),
            'subscription_plan' => new SubscriptionPlanResource($this->whenLoaded('subscriptionPlan')),
            'created_at' => $this->created_at?->toLocalIso8601String(),
        ];
    }
}
