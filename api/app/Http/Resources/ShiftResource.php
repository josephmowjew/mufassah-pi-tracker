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
        // Resolved once, up front. break_count, break_minutes, worked_minutes
        // and on_break all read this relation, and the breaks collection below
        // emits it — without this, a shift whose breaks were not eager-loaded
        // would either issue four separate queries or, if the key order in this
        // array ever changed, serialise the collection from a MissingValue.
        if (! $this->relationLoaded('breaks')) {
            $this->load('breaks');
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => new BranchResource($this->branch)),
            'user' => $this->whenLoaded('user', fn () => new UserResource($this->user)),
            'started_at' => $this->started_at?->toLocalIso8601String(),
            'ended_at' => $this->ended_at?->toLocalIso8601String(),
            'status' => $this->status,
            'notes' => $this->notes,
            'opening_cash' => (float) $this->opening_cash,
            'closing_cash' => $this->closing_cash ? (float) $this->closing_cash : null,
            'duration_minutes' => $this->duration,
            'break_count' => $this->break_count,
            'break_minutes' => $this->break_minutes,
            'worked_minutes' => $this->worked_minutes,
            'on_break' => $this->on_break,
            'breaks' => ShiftBreakResource::collection($this->breaks),
            'revenue' => (float) ($this->revenue ?? 0),
            'sales_count' => $this->sales_count ?? 0,
            'cash_sales' => (float) ($this->cash_sales ?? 0),
            'created_at' => $this->created_at?->toLocalIso8601String(),
            'updated_at' => $this->updated_at?->toLocalIso8601String(),
        ];
    }
}
