<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\FamilyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::enum(FamilyRole::class)],
            'nickname' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please provide the member\'s email address.',
            'email.email' => 'Please provide a valid email address.',
            'email.exists' => 'No user found with this email address.',
            'role.required' => 'Please select a role for this member.',
            'role.enum' => 'Invalid role selected.',
            'nickname.max' => 'Nickname cannot exceed 50 characters.',
        ];
    }
}
