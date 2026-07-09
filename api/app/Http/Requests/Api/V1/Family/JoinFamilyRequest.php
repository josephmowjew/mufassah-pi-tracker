<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

class JoinFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:8', 'exists:families,code'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Please provide the family invite code.',
            'code.size' => 'Invite code must be exactly 8 characters.',
            'code.exists' => 'Invalid invite code. Please check and try again.',
        ];
    }
}
