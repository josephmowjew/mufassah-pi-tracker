<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isPublic = $request->routeIs('invoices.public-show');
        $canViewCost = ! $isPublic && (bool) $request->user()?->can('view-device-costs');
        $remainingSeconds = max(0, now()->diffInSeconds($this->expires_at, false));

        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'customer_id' => $this->customer_id,
            'recipient_email' => $this->recipient_email,
            'branch_id' => $this->branch_id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'issued_at' => $this->issued_at?->toLocalIso8601String(),
            'expires_at' => $this->expires_at?->toLocalIso8601String(),
            'remaining_seconds' => $remainingSeconds,
            'is_redeemable' => $this->isRedeemable(),
            'vat_applicable' => $this->vat_applicable,
            'tax_rate' => $this->tax_rate,
            'tax_label' => $this->tax_label,
            'tax_display' => $this->vat_applicable
                ? sprintf('%s (%s%%)', $this->tax_label, $this->tax_rate)
                : 'Not applicable',
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'emailed_at' => $this->emailed_at?->toLocalIso8601String(),
            'redeemed_at' => $this->redeemed_at?->toLocalIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toLocalIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'converted_sale_id' => $this->converted_sale_id,
            'cost_total' => $this->when(
                $canViewCost && $this->relationLoaded('items'),
                fn () => round((float) $this->items->sum('cost_price'), 2),
            ),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'customer' => new UserResource($this->whenLoaded('customer')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'converted_sale' => new SaleResource($this->whenLoaded('convertedSale')),
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
