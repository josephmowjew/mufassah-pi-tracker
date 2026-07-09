<?php

namespace App\Http\Requests\Api\V1\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class UpgradeDowngradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Please select a new plan.',
            'plan_id.exists' => 'The selected plan does not exist.',
            'billing_cycle.in' => 'Billing cycle must be monthly or yearly.',
        ];
    }
}
