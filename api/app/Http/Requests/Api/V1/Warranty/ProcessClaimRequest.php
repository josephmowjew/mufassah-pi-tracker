<?php

namespace App\Http\Requests\Api\V1\Warranty;

use App\Enums\WarrantyClaimStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(WarrantyClaimStatus::class)],
            'resolution_type' => ['required_if:status,completed', 'nullable', 'string', 'in:repair,replacement,refund'],
            'resolution_notes' => ['required', 'string', 'max:1000'],
            'repair_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Please select a status.',
            'status.enum' => 'Invalid status. Valid options: '.implode(', ', WarrantyClaimStatus::values()),
            'resolution_type.required_if' => 'Resolution type is required when completing a claim.',
            'resolution_notes.required' => 'Please provide resolution notes.',
            'repair_cost.min' => 'Repair cost cannot be negative.',
        ];
    }
}
