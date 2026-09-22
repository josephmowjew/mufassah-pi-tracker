<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'assigned_to.required' => 'Please select a user to assign this report to.',
            'assigned_to.exists' => 'The selected user does not exist.',
        ];
    }
}
