<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $summary = $this->summary();

        return [
            'id' => $this->id,
            'batch_number' => $this->batch_number,
            'inventory_receipt_id' => $this->inventory_receipt_id,
            'branch_id' => $this->branch_id,
            'category_id' => $this->category_id,
            'brand' => $this->brand,
            'model' => $this->model,
            'color' => $this->color,
            'quantity_received' => $this->quantity_received,
            'buying_price' => $this->buying_price,
            'target_selling_price' => $this->target_selling_price,
            'purchase_date' => $this->purchase_date?->toDateString(),
            'purchase_location' => $this->purchase_location,
            'notes' => $this->notes,
            'summary' => $summary,
            'receipt' => new InventoryReceiptResource($this->whenLoaded('receipt')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'category' => new DeviceCategoryResource($this->whenLoaded('category')),
            'devices' => DeviceResource::collection($this->whenLoaded('devices')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
