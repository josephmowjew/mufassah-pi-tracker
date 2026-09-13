<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unique_device_code' => $this->unique_device_code,
            'serial_number' => $this->serial_number,
            'name' => "{$this->brand} {$this->model}",  // Computed display name
            'brand' => $this->brand,
            'model' => $this->model,
            'description' => $this->description,
            'color' => $this->color,
            'purchase_date' => $this->purchase_date?->toDateString(),

            // Cost and margin are internal: purchase_price doubles as the
            // wholesale cost for inventory-sourced devices, and the admin
            // inventory screens derive margin from it. Null rather than omitted so
            // the field keeps its shape and clients render "not available"
            // naturally instead of tripping over a missing key.
            'purchase_price' => $this->when(
                $request->user()?->can('view-device-costs'),
                fn () => $this->purchase_price,
                null,
            ),
            'purchase_location' => $this->when(
                $request->user()?->can('view-device-costs'),
                fn () => $this->purchase_location,
                null,
            ),
            'inventory_batch_id' => $this->inventory_batch_id,
            'branch_id' => $this->branch_id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'notes' => $this->notes,
            'photos' => $this->photos,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'is_stolen' => $this->isStolen(),
            'has_iot_module' => $this->hasIotModule(),

            // Inventory fields
            'inventory_status' => $this->when($this->inventory_status, fn () => [
                'value' => $this->inventory_status->value,
                'label' => $this->inventory_status->label(),
                'color' => $this->inventory_status->color(),
            ]),
            'is_in_stock' => $this->isInStock(),
            'is_sold' => $this->isSold(),
            'sold_at' => $this->sold_at?->toIso8601String(),

            // Relationships
            'owner' => new UserResource($this->whenLoaded('owner')),
            'sold_by' => new UserResource($this->whenLoaded('soldBy')),
            'category' => new DeviceCategoryResource($this->whenLoaded('category')),
            'inventory_batch' => new InventoryBatchResource($this->whenLoaded('inventoryBatch')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'iot_module' => new IotModuleResource($this->whenLoaded('iotModule')),
            'last_location' => new DeviceLocationResource($this->whenLoaded('lastLocation')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
