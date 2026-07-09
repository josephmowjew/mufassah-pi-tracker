<?php

namespace App\Http\Requests;

use App\Enums\GeofenceShapeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGeofenceRequest extends FormRequest
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
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'shape_type' => ['required', Rule::enum(GeofenceShapeType::class)],
            'center_latitude' => ['required', 'numeric', 'between:-90,90'],
            'center_longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required_if:shape_type,circle', 'nullable', 'numeric', 'min:10', 'max:50000'],
            'polygon_coordinates' => ['required_if:shape_type,polygon', 'nullable', 'array', 'min:3'],
            'polygon_coordinates.*.latitude' => ['required_with:polygon_coordinates', 'numeric', 'between:-90,90'],
            'polygon_coordinates.*.longitude' => ['required_with:polygon_coordinates', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
            'notify_on_entry' => ['boolean'],
            'notify_on_exit' => ['boolean'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['string', Rule::in(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])],
            'active_time_start' => ['nullable', 'date_format:H:i'],
            'active_time_end' => ['nullable', 'date_format:H:i', 'after:active_time_start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'radius_meters.required_if' => 'Radius is required for circular geofences.',
            'polygon_coordinates.required_if' => 'Polygon coordinates are required for polygon geofences.',
            'polygon_coordinates.min' => 'A polygon must have at least 3 coordinates.',
            'active_time_end.after' => 'End time must be after start time.',
        ];
    }
}
