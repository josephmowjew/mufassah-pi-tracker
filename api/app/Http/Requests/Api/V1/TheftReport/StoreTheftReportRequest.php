<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTheftReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'theft_date' => ['required', 'date', 'before_or_equal:now'],
            'theft_location' => ['required', 'string', 'max:255'],
            'theft_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'theft_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['required', 'string', 'max:2000'],
            'police_report_number' => ['nullable', 'string', 'max:50'],
            'police_station' => ['nullable', 'string', 'max:100'],
            'officer_name' => ['nullable', 'string', 'max:100'],
            'officer_contact' => ['nullable', 'string', 'max:20'],
            'evidence_photos' => ['nullable', 'array', 'max:10'],
            'evidence_photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.required' => 'Please select the device that was stolen.',
            'device_id.exists' => 'The selected device does not exist.',
            'theft_date.required' => 'Please provide the date of theft.',
            'theft_date.before_or_equal' => 'Theft date cannot be in the future.',
            'theft_location.required' => 'Please provide the location where the theft occurred.',
            'description.required' => 'Please provide a description of the theft.',
            'description.max' => 'Description cannot exceed 2000 characters.',
            'evidence_photos.max' => 'You can upload a maximum of 10 evidence photos.',
            'evidence_photos.*.image' => 'Each file must be an image.',
            'evidence_photos.*.max' => 'Each photo must be less than 5MB.',
        ];
    }
}
