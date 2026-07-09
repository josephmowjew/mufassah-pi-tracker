<?php

namespace App\Http\Requests\Api\V1\Subscription;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
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
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Please select a subscription plan.',
            'plan_id.exists' => 'The selected plan does not exist.',
            'billing_cycle.required' => 'Please select a billing cycle.',
            'billing_cycle.in' => 'Billing cycle must be monthly or yearly.',
        ];
    }
}
