<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
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
            'user_id' => $this->user_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => new BranchResource($this->branch)),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'status' => $this->status,
            'notes' => $this->notes,
            'opening_cash' => (float) $this->opening_cash,
            'closing_cash' => $this->closing_cash ? (float) $this->closing_cash : null,
            'duration_minutes' => $this->duration,
            'revenue' => (float) ($this->revenue ?? 0),
            'sales_count' => $this->sales_count ?? 0,
            'cash_sales' => (float) ($this->cash_sales ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
