<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
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
            'type' => $this->data['type'] ?? class_basename($this->type),
            'title' => $this->data['title'] ?? 'Notification',
            'message' => $this->data['message'] ?? '',
            'data' => $this->data,
            'read_at' => $this->read_at?->toLocalIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at->toLocalIso8601String(),
        ];
    }
}
