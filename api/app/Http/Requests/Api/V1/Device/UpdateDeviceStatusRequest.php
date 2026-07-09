<?php

namespace App\Http\Requests\Api\V1\Device;

use App\Enums\DeviceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(DeviceStatus::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Please provide the new status.',
            'status.enum' => 'Invalid status. Valid options: '.implode(', ', DeviceStatus::values()),
        ];
    }
}
