<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'billing_cycle' => $this->billing_cycle,
            'starts_at' => $this->starts_at?->toLocalIso8601String(),
            'ends_at' => $this->ends_at?->toLocalIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toLocalIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'auto_renew' => $this->auto_renew,
            'is_active' => $this->isActive(),
            'days_remaining' => $this->ends_at?->isFuture() ? now()->diffInDays($this->ends_at) : 0,
            'plan' => new SubscriptionPlanResource($this->whenLoaded('plan')),
            'user' => new UserResource($this->whenLoaded('user')),
            'last_payment' => new PaymentResource($this->whenLoaded('lastPayment')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
