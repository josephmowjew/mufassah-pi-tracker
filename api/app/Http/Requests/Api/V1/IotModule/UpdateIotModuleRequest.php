<?php

namespace App\Http\Requests\Api\V1\IotModule;

use App\Enums\IotModuleStatus;
use App\Enums\ModemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIotModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'sim_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'imei' => ['sometimes', 'nullable', 'string', 'max:20'],
            'modem_type' => ['sometimes', Rule::enum(ModemType::class)],
            'firmware_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'hardware_version' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::enum(IotModuleStatus::class)],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'modem_type.enum' => 'Invalid modem type. Valid options: '.implode(', ', ModemType::values()),
            'status.enum' => 'Invalid status. Valid options: '.implode(', ', IotModuleStatus::values()),
        ];
    }
}
