<?php

namespace App\Http\Requests\Api\V1\Device;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkIotModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'iot_module_id' => [
                'required',
                'integer',
                Rule::exists('iot_modules', 'id')->whereNull('deleted_at'),
                function ($attribute, $value, $fail) {
                    // Check if the module is already linked to another device
                    $linkedDevice = Device::where('iot_module_id', $value)
                        ->where('id', '!=', $this->route('device')?->id)
                        ->first();

                    if ($linkedDevice) {
                        $fail('This IoT module is already linked to another device.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'iot_module_id.required' => 'Please provide the IoT module ID.',
            'iot_module_id.exists' => 'The specified IoT module does not exist.',
        ];
    }
}
