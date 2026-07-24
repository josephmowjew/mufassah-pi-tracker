<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'location' => $this->location,
            'employment_type' => $this->employment_type,
            'description' => $this->description,
            'application_email' => $this->application_email,
            'closing_date' => $this->closing_date?->toDateString(),
            'sort_order' => $this->when($request->user(), $this->sort_order),
            'is_published' => $this->when($request->user(), $this->is_published),
            'published_at' => $this->when($request->user(), $this->published_at?->toIso8601String()),
            'created_at' => $this->when($request->user(), $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($request->user(), $this->updated_at?->toIso8601String()),
        ];
    }
}
