<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use Illuminate\Foundation\Http\FormRequest;

class AssignReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
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
