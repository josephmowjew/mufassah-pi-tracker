<?php

namespace App\Http\Requests\Api\V1\Family;

use App\Enums\FamilyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(FamilyRole::class)],
            'nickname' => ['nullable', 'string', 'max:50'],
            'location_sharing_enabled' => ['sometimes', 'boolean'],
            'receive_family_alerts' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.enum' => 'Invalid role selected.',
            'nickname.max' => 'Nickname cannot exceed 50 characters.',
        ];
    }
}
