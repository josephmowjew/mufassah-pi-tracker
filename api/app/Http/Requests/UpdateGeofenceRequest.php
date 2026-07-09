<?php

namespace App\Http\Requests;

use App\Enums\GeofenceShapeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeofenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'shape_type' => ['sometimes', Rule::enum(GeofenceShapeType::class)],
            'center_latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'center_longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'numeric', 'min:10', 'max:50000'],
            'polygon_coordinates' => ['nullable', 'array', 'min:3'],
            'polygon_coordinates.*.latitude' => ['required_with:polygon_coordinates', 'numeric', 'between:-90,90'],
            'polygon_coordinates.*.longitude' => ['required_with:polygon_coordinates', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
            'notify_on_entry' => ['boolean'],
            'notify_on_exit' => ['boolean'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'active_time_start' => ['nullable', 'date_format:H:i'],
            'active_time_end' => ['nullable', 'date_format:H:i'],
        ];
    }
}
