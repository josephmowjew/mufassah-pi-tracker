<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_number' => $this->movement_number,
            'movement_type' => $this->movement_type,
            'device_id' => $this->device_id,
            'inventory_batch_id' => $this->inventory_batch_id,
            'inventory_receipt_id' => $this->inventory_receipt_id,
            'sale_id' => $this->sale_id,
            'sale_item_id' => $this->sale_item_id,
            'category_id' => $this->category_id,
            'branch_id' => $this->branch_id,
            'from_branch_id' => $this->from_branch_id,
            'to_branch_id' => $this->to_branch_id,
            'brand' => $this->brand,
            'model' => $this->model,
            'color' => $this->color,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'unit_price' => $this->unit_price,
            'total_cost' => $this->total_cost,
            'total_amount' => $this->total_amount,
            'profit_amount' => $this->profit_amount,
            'previous_status' => $this->previous_status,
            'new_status' => $this->new_status,
            'previous_owner_id' => $this->previous_owner_id,
            'new_owner_id' => $this->new_owner_id,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_by_id' => $this->created_by_id,
            'is_backfilled' => $this->is_backfilled,
            'metadata' => $this->metadata,
            'device' => new DeviceResource($this->whenLoaded('device')),
            'batch' => new InventoryBatchResource($this->whenLoaded('batch')),
            'receipt' => new InventoryReceiptResource($this->whenLoaded('receipt')),
            'sale' => new SaleResource($this->whenLoaded('sale')),
            'category' => new DeviceCategoryResource($this->whenLoaded('category')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'from_branch' => new BranchResource($this->whenLoaded('fromBranch')),
            'to_branch' => new BranchResource($this->whenLoaded('toBranch')),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
