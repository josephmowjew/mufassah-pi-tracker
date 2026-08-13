<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sale_number' => $this->sale_number,
            'branch_id' => $this->branch_id,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'payment_method' => [
                'value' => $this->payment_method->value,
                'label' => $this->payment_method->label(),
            ],
            'payment_status' => [
                'value' => $this->payment_status->value,
                'label' => $this->payment_status->label(),
                'color' => $this->payment_status->color(),
            ],
            'payment_reference' => $this->payment_reference,
            'payment_amount' => $this->payment_amount,
            'change_due' => $this->change_due,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'notes' => $this->notes,
            'refund_reason' => $this->refund_reason,
            'sale_date' => $this->sale_date?->toIso8601String(),
            'vat_applicable' => $this->vat_applicable ?? true,
            'is_completed' => $this->isCompleted(),
            'is_paid' => $this->isPaid(),

            // Item summary
            'items_count' => $this->whenCounted('items'),

            // Relationships
            'customer' => new UserResource($this->whenLoaded('customer')),
            'sold_by' => new UserResource($this->whenLoaded('soldBy')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'items' => SaleItemResource::collection($this->whenLoaded('items')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
