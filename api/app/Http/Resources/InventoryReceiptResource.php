<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'branch_id' => $this->branch_id,
            'supplier' => $this->supplier,
            'reference_number' => $this->reference_number,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_location' => $this->purchase_location,
            'status' => $this->status,
            'notes' => $this->notes,
            'received_by_id' => $this->received_by_id,
            'received_at' => $this->received_at?->toIso8601String(),
            'summary' => $this->summary(),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'received_by' => new UserResource($this->whenLoaded('receivedBy')),
            'batches' => InventoryBatchResource::collection($this->whenLoaded('batches')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
