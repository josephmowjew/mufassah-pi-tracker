<?php

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:device_categories,id'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'purchase_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'purchase_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'purchase_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
            'purchase_price.min' => 'Purchase price cannot be negative.',
        ];
    }
}
