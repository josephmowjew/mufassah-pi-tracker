<?php

namespace App\Http\Requests\Api\V1\Warranty;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'integer', 'exists:devices,id', 'unique:warranties,device_id,NULL,id,deleted_at,NULL'],
            'start_date' => ['required', 'date'],
            'duration_months' => ['required', 'integer', 'min:1', 'max:120'],
            'coverage_details' => ['nullable', 'string', 'max:1000'],
            'terms_conditions' => ['nullable', 'array'],
            'provider' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.required' => 'Please select a device.',
            'device_id.exists' => 'The selected device does not exist.',
            'device_id.unique' => 'This device already has an active warranty.',
            'start_date.required' => 'Please provide the warranty start date.',
            'duration_months.required' => 'Please provide the warranty duration.',
            'duration_months.min' => 'Warranty duration must be at least 1 month.',
            'duration_months.max' => 'Warranty duration cannot exceed 120 months.',
        ];
    }
}
