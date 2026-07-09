<?php

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

class TransferDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_user_email' => ['required', 'email', 'exists:users,email'],
            'transfer_reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_user_email.required' => 'Please provide the email of the new owner.',
            'to_user_email.email' => 'Please provide a valid email address.',
            'to_user_email.exists' => 'No user found with this email address.',
            'transfer_reason.required' => 'Please provide a reason for the transfer.',
        ];
    }
}
