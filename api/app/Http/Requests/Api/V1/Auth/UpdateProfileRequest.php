<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users', 'phone_number')->ignore($userId),
                'regex:/^(\+265|265|0)[0-9]{9}$/',
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'Name cannot exceed 255 characters.',
            'phone_number.unique' => 'This phone number is already registered.',
            'phone_number.regex' => 'Please provide a valid Malawi phone number (e.g., +265999123456).',
            'address.max' => 'Address cannot exceed 500 characters.',
            'city.max' => 'City cannot exceed 100 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->phone_number) {
            $this->merge([
                'phone_number' => format_phone_number($this->phone_number),
            ]);
        }
    }
}
