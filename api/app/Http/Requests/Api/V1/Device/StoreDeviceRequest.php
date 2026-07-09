<?php

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:100', 'unique:devices,serial_number'],
            'category_id' => ['required', 'integer', 'exists:device_categories,id'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:50'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Please provide the device serial number.',
            'serial_number.unique' => 'This serial number is already registered.',
            'category_id.required' => 'Please select a device category.',
            'category_id.exists' => 'The selected category does not exist.',
            'brand.required' => 'Please provide the device brand.',
            'model.required' => 'Please provide the device model.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
            'purchase_price.min' => 'Purchase price cannot be negative.',
            'photos.max' => 'You can upload a maximum of 5 photos.',
            'photos.*.image' => 'Each file must be an image.',
            'photos.*.max' => 'Each photo must be less than 2MB.',
        ];
    }
}
