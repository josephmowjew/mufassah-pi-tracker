<?php

namespace App\Notifications;

use App\Models\InventoryActionChallenge;
use Illuminate\Notifications\Notification;

class InventoryActionPinAvailableNotification extends Notification
{
    public function __construct(
        public readonly InventoryActionChallenge $challenge,
        public readonly string $actionLabel,
        public readonly string $targetLabel,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'inventory_action_pin',
            'title' => 'Approval PIN available',
            'message' => "A secure PIN is available for {$this->actionLabel}.",
            'challenge_id' => $this->challenge->id,
            'action' => $this->challenge->action,
            'action_label' => $this->actionLabel,
            'target_label' => $this->targetLabel,
            'device_count' => count((array) ($this->challenge->payload['device_ids'] ?? [])) ?: 1,
            'issued_at' => $this->challenge->last_sent_at?->toLocalIso8601String(),
            'expires_at' => $this->challenge->expires_at?->toLocalIso8601String(),
            'status' => 'active',
        ];
    }
}
