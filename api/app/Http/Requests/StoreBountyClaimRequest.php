<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUtcDates;
use Illuminate\Foundation\Http\FormRequest;

class StoreBountyClaimRequest extends FormRequest
{
    use NormalizesUtcDates;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUtcDateInput('recovery_date');
    }

    public function rules(): array
    {
        return [
            'claim_type' => ['required', 'string', 'in:recovery,tip_leading_to_recovery,partial_recovery'],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'evidence_photos' => ['required', 'array', 'min:1', 'max:10'],
            'evidence_photos.*' => ['string', 'max:500'],
            'recovery_latitude' => ['required', 'numeric', 'between:-90,90'],
            'recovery_longitude' => ['required', 'numeric', 'between:-180,180'],
            'recovery_address' => ['nullable', 'string', 'max:500'],
            'recovery_date' => ['required', 'date', 'before_or_equal:now'],
            'claim_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:airtel_money,tnm_mpamba,bank_transfer,cash'],
            'payment_account' => ['required', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'claim_type.required' => 'Please specify the type of claim.',
            'claim_type.in' => 'Claim type must be one of: recovery, tip_leading_to_recovery, or partial_recovery.',
            'description.required' => 'Please provide details about how you helped recover the device.',
            'description.min' => 'Description must be at least 20 characters.',
            'evidence_photos.required' => 'Please upload at least one photo as evidence.',
            'evidence_photos.min' => 'Please upload at least one photo as evidence.',
            'evidence_photos.max' => 'You can upload a maximum of 10 photos.',
            'recovery_latitude.required' => 'Please provide the recovery location.',
            'recovery_longitude.required' => 'Please provide the recovery location.',
            'recovery_date.required' => 'Please specify when the device was recovered.',
            'recovery_date.before_or_equal' => 'Recovery date cannot be in the future.',
            'payment_method.required' => 'Please select a payment method.',
            'payment_method.in' => 'Payment method must be one of: Airtel Money, TNM Mpamba, Bank Transfer, or Cash.',
            'payment_account.required' => 'Please provide your payment account details.',
        ];
    }
}
