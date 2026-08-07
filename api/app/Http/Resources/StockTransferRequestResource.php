<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'request_number' => $this->request_number, 'status' => $this->status, 'from_branch_id' => $this->from_branch_id, 'to_branch_id' => $this->to_branch_id,
            'requested_by_id' => $this->requested_by_id, 'decided_by_id' => $this->decided_by_id, 'is_override' => (bool) $this->is_override, 'overridden_by_id' => $this->overridden_by_id,
            'reason' => $this->reason, 'notes' => $this->notes, 'decision_reason' => $this->decision_reason,
            'decided_at' => $this->decided_at?->toIso8601String(), 'overridden_at' => $this->overridden_at?->toIso8601String(), 'item_count' => $this->whenCounted('items'), 'from_branch' => new BranchResource($this->whenLoaded('fromBranch')),
            'to_branch' => new BranchResource($this->whenLoaded('toBranch')), 'requested_by' => new UserResource($this->whenLoaded('requestedBy')),
            'decided_by' => new UserResource($this->whenLoaded('decidedBy')), 'overridden_by' => new UserResource($this->whenLoaded('overriddenBy')), 'items' => StockTransferRequestItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
