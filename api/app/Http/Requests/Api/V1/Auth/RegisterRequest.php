<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers(),
            ],
            'phone_number' => [
                'required',
                'string',
                'max:20',
                'unique:users,phone_number',
                'regex:/^(\+265|265|0)[0-9]{9}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide your full name.',
            'email.required' => 'Please provide your email address.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            'password.required' => 'Please provide a password.',
            'password.confirmed' => 'Password confirmation does not match.',
            'phone_number.required' => 'Please provide your phone number.',
            'phone_number.unique' => 'This phone number is already registered.',
            'phone_number.regex' => 'Please provide a valid Malawi phone number (e.g., +265999123456).',
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
