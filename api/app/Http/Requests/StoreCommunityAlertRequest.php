<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUtcDates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityAlertRequest extends FormRequest
{
    use NormalizesUtcDates;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUtcDateInput('expires_at');
    }

    public function rules(): array
    {
        return [
            'theft_report_id' => ['required', Rule::exists('theft_reports', 'id')->whereNull('deleted_at')],
            'broadcast_latitude' => ['required', 'numeric', 'between:-90,90'],
            'broadcast_longitude' => ['required', 'numeric', 'between:-180,180'],
            'alert_radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'opt_in_notifications' => ['nullable', 'boolean'],
            'bounty_amount' => ['nullable', 'numeric', 'min:0'],
            'bounty_currency' => ['nullable', 'string', 'max:3'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'theft_report_id.required' => 'A theft report is required to create a community alert.',
            'theft_report_id.exists' => 'The specified theft report does not exist.',
            'broadcast_latitude.required' => 'Location latitude is required.',
            'broadcast_longitude.required' => 'Location longitude is required.',
            'alert_radius_km.max' => 'Alert radius cannot exceed 100 km.',
            'bounty_amount.min' => 'Bounty amount cannot be negative.',
            'expires_at.after' => 'Expiry date must be in the future.',
        ];
    }
}
