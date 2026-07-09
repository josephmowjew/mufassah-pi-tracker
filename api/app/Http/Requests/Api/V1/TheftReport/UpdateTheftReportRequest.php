<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTheftReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin', 'customer_service'])
            || $this->route('theft_report')->reported_by === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'theft_date' => ['sometimes', 'date', 'before_or_equal:now'],
            'theft_location' => ['sometimes', 'string', 'max:255'],
            'theft_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'theft_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'police_report_number' => ['nullable', 'string', 'max:50'],
            'police_station' => ['nullable', 'string', 'max:100'],
            'officer_name' => ['nullable', 'string', 'max:100'],
            'officer_contact' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'theft_date.before_or_equal' => 'Theft date cannot be in the future.',
            'description.max' => 'Description cannot exceed 2000 characters.',
        ];
    }
}
