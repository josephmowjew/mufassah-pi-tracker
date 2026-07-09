<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
            'settings' => ['nullable', 'array'],
            'settings.location_update_interval' => ['nullable', 'integer', 'min:1', 'max:60'],
            'settings.allow_member_invites' => ['nullable', 'boolean'],
            'settings.require_approval_for_join' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'Family name cannot exceed 100 characters.',
            'avatar.image' => 'Avatar must be an image file.',
            'avatar.max' => 'Avatar must be less than 2MB.',
            'settings.location_update_interval.min' => 'Location update interval must be at least 1 minute.',
            'settings.location_update_interval.max' => 'Location update interval cannot exceed 60 minutes.',
        ];
    }
}
