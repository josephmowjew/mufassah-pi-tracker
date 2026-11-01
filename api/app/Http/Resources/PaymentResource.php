<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'payment_number' => $this->payment_number,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'payment_method' => [
                'value' => $this->payment_method->value,
                'label' => $this->payment_method->label(),
            ],
            'payment_provider' => $this->payment_provider,
            'provider_transaction_id' => $this->provider_transaction_id,
            'provider_reference' => $this->provider_reference,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'paid_at' => $this->paid_at?->toLocalIso8601String(),
            'subscription' => new SubscriptionResource($this->whenLoaded('subscription')),
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
