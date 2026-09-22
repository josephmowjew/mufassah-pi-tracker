<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkStoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'iot_module_id' => ['nullable', 'integer', 'exists:iot_modules,id'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'buying_price' => ['required', 'numeric', 'min:0'],
            'target_selling_price' => ['required', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_generate_serials' => ['boolean'],
            'serial_numbers' => ['nullable', 'array'],
            'serial_numbers.*' => ['required', 'string', 'max:100', 'distinct', 'unique:devices,serial_number'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Please select a device category.',
            'category_id.exists' => 'The selected category does not exist.',
            'brand.required' => 'Please provide the device brand.',
            'model.required' => 'Please provide the device model.',
            'quantity.required' => 'Please specify the number of devices to add.',
            'quantity.min' => 'You must add at least 1 device.',
            'quantity.max' => 'You can add a maximum of 100 devices at once.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
            'buying_price.required' => 'Please enter the buying price for this batch.',
            'buying_price.min' => 'Buying price cannot be negative.',
            'target_selling_price.required' => 'Please enter the target selling price for this batch.',
            'target_selling_price.min' => 'Target selling price cannot be negative.',
            'purchase_price.min' => 'Purchase price cannot be negative.',
            'serial_numbers.*.distinct' => 'Serial numbers must be unique within the batch.',
            'serial_numbers.*.unique' => 'One or more serial numbers already exist in the system.',
            'serial_numbers.*.max' => 'Each serial number must be 100 characters or less.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Default auto_generate_serials to true if not provided
        if (! $this->has('auto_generate_serials')) {
            $this->merge(['auto_generate_serials' => true]);
        }

        if (! $this->has('buying_price') && $this->has('purchase_price')) {
            $this->merge(['buying_price' => $this->input('purchase_price')]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $autoGenerate = $this->boolean('auto_generate_serials');
            $serialNumbers = $this->input('serial_numbers', []);
            $quantity = $this->input('quantity', 0);

            // If not auto-generating, validate that serial numbers count matches quantity
            if (! $autoGenerate && count($serialNumbers) !== $quantity) {
                $validator->errors()->add(
                    'serial_numbers',
                    "When not auto-generating serial numbers, you must provide exactly {$quantity} serial numbers."
                );
            }
        });
    }
}
