<?php

namespace App\Http\Requests\Api\V1\TheftReport;

use Illuminate\Foundation\Http\FormRequest;

class AddEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $report = $this->route('theft_report');

        return $this->user()->hasRole(['super_admin', 'store_admin', 'customer_service'])
            || $report->reported_by === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'photos.required' => 'Please upload at least one evidence photo.',
            'photos.min' => 'Please upload at least one evidence photo.',
            'photos.max' => 'You can upload a maximum of 10 photos at once.',
            'photos.*.image' => 'Each file must be an image.',
            'photos.*.mimes' => 'Photos must be in JPEG or PNG format.',
            'photos.*.max' => 'Each photo must be less than 5MB.',
        ];
    }
}
