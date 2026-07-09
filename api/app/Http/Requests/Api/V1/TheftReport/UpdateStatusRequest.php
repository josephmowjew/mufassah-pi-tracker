<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use App\Enums\TheftReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TheftReportStatus::class)],
            'notes' => ['required', 'string', 'max:1000'],
            'is_public' => ['sometimes', 'boolean'],
            'recovery_location' => ['required_if:status,recovered', 'nullable', 'string', 'max:255'],
            'recovery_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Please select the new status.',
            'status.enum' => 'Invalid status. Valid options: '.implode(', ', TheftReportStatus::values()),
            'notes.required' => 'Please provide notes for this status change.',
            'recovery_location.required_if' => 'Recovery location is required when marking as recovered.',
        ];
    }
}
