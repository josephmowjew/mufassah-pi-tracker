<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (! Hash::check($value, $this->user()->password)) {
                        $fail('The current password is incorrect.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::min(8)
                    ->mixedCase()
                    ->numbers(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Please provide your current password.',
            'password.required' => 'Please provide a new password.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.different' => 'New password must be different from your current password.',
        ];
    }
}
