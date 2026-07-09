<?php

namespace App\Http\Requests\Api\V1\Family;

use Illuminate\Foundation\Http\FormRequest;

class StoreFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide a family name.',
            'name.max' => 'Family name cannot exceed 100 characters.',
            'avatar.image' => 'Avatar must be an image file.',
            'avatar.max' => 'Avatar must be less than 2MB.',
        ];
    }
}
