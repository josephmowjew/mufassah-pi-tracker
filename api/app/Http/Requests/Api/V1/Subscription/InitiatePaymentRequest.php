<?php

namespace App\Http\Requests\Api\V1\Subscription;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiatePaymentRequest extends FormRequest
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
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'phone_number' => ['required_if:payment_method,mobile_money', 'nullable', 'string', 'regex:/^(\+?265|0)?[89]\d{8}$/'],
            'return_url' => ['sometimes', 'url'],
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
            'payment_method.required' => 'Please select a payment method.',
            'phone_number.required_if' => 'Phone number is required for mobile money payments.',
            'phone_number.regex' => 'Please provide a valid Malawi phone number.',
        ];
    }
}
