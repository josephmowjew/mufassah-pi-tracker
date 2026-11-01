<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftBreakResource extends JsonResource
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
            'shift_id' => $this->shift_id,
            'type' => $this->type?->value,
            'type_label' => $this->type?->label(),
            'started_at' => $this->started_at?->toLocalIso8601String(),
            'ended_at' => $this->ended_at?->toLocalIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'is_open' => $this->isOpen(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
