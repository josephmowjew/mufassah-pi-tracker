<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInventoryReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'supplier' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.category_id' => ['required', 'integer', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'lines.*.brand' => ['required', 'string', 'max:100'],
            'lines.*.model' => ['required', 'string', 'max:100'],
            'lines.*.color' => ['nullable', 'string', 'max:50'],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'lines.*.buying_price' => ['required', 'numeric', 'min:0'],
            'lines.*.target_selling_price' => ['required', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.auto_generate_serials' => ['boolean'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['required', 'string', 'max:100', 'distinct', 'unique:devices,serial_number'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Please select the receiving branch.',
            'purchase_date.before_or_equal' => 'Purchase date cannot be in the future.',
            'lines.required' => 'Please add at least one product line.',
            'lines.min' => 'Please add at least one product line.',
            'lines.max' => 'You can receive a maximum of 50 product lines at once.',
            'lines.*.category_id.required' => 'Each product line needs a category.',
            'lines.*.brand.required' => 'Each product line needs a brand.',
            'lines.*.model.required' => 'Each product line needs a model.',
            'lines.*.quantity.required' => 'Each product line needs a quantity.',
            'lines.*.quantity.min' => 'Each product line must receive at least 1 unit.',
            'lines.*.quantity.max' => 'Each product line can receive a maximum of 100 units.',
            'lines.*.buying_price.required' => 'Each product line needs a buying price.',
            'lines.*.target_selling_price.required' => 'Each product line needs a target selling price.',
            'lines.*.serial_numbers.*.distinct' => 'Serial numbers must be unique within the product line.',
            'lines.*.serial_numbers.*.unique' => 'One or more serial numbers already exist in the system.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(function (array $line) {
                $line['auto_generate_serials'] ??= true;

                if (! array_key_exists('buying_price', $line) && array_key_exists('purchase_price', $line)) {
                    $line['buying_price'] = $line['purchase_price'];
                }

                return $line;
            })
            ->all();

        $this->merge(['lines' => $lines]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('lines', []) as $index => $line) {
                $autoGenerate = filter_var($line['auto_generate_serials'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $serialNumbers = $line['serial_numbers'] ?? [];
                $quantity = (int) ($line['quantity'] ?? 0);

                if (! $autoGenerate && count($serialNumbers) !== $quantity) {
                    $lineNumber = $index + 1;
                    $validator->errors()->add(
                        "lines.{$index}.serial_numbers",
                        "Line {$lineNumber} needs exactly {$quantity} serial numbers."
                    );
                }
            }
        });
    }
}
