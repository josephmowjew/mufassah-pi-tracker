<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TheftReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'theft_date' => $this->theft_date?->toIso8601String(),
            'theft_location' => $this->theft_location,
            'theft_latitude' => $this->theft_latitude,
            'theft_longitude' => $this->theft_longitude,
            'description' => $this->description,

            // Police information
            'police_report_number' => $this->police_report_number,
            'police_station' => $this->police_station,
            'officer_name' => $this->officer_name,
            'officer_contact' => $this->officer_contact,

            // Evidence
            'evidence_photos' => $this->evidence_photos,

            // Recovery information
            'recovered_at' => $this->recovered_at?->toIso8601String(),
            'recovery_location' => $this->recovery_location,
            'recovery_notes' => $this->recovery_notes,

            // Relationships
            'device' => new DeviceResource($this->whenLoaded('device')),
            'reporter' => new UserResource($this->whenLoaded('reporter')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'updates' => TheftReportUpdateResource::collection($this->whenLoaded('updates')),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
