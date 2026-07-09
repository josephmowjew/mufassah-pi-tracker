<?php

namespace App\Http\Requests\Api\V1\IotModule;

use App\Enums\ModemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIotModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'module_id' => ['required', 'string', 'max:50', 'unique:iot_modules,module_id'],
            'sim_number' => ['required', 'string', 'max:20'],
            'imei' => ['required', 'string', 'size:15', 'unique:iot_modules,imei'],
            'modem_type' => ['required', Rule::enum(ModemType::class)],
            'firmware_version' => ['nullable', 'string', 'max:20'],
            'hardware_version' => ['nullable', 'string', 'max:20'],
            'configuration' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'module_id.required' => 'Please provide the module ID.',
            'module_id.unique' => 'This module ID is already registered.',
            'sim_number.required' => 'Please provide the SIM number.',
            'imei.required' => 'Please provide the IMEI number.',
            'imei.size' => 'IMEI must be exactly 15 digits.',
            'imei.unique' => 'This IMEI is already registered.',
            'modem_type.required' => 'Please select the modem type.',
            'modem_type.enum' => 'Invalid modem type. Valid options: '.implode(', ', ModemType::values()),
        ];
    }
}
