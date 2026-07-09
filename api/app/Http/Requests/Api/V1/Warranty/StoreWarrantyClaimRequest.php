<?php

namespace App\Http\Requests\Api\V1\Warranty;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issue_description' => ['required', 'string', 'max:2000'],
            'issue_category' => ['required', 'string', 'in:hardware,software,battery,screen,other'],
            'evidence_photos' => ['nullable', 'array', 'max:10'],
            'evidence_photos.*' => ['image', 'mimes:jpeg,png,jpg', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'issue_description.required' => 'Please describe the issue.',
            'issue_description.max' => 'Issue description cannot exceed 2000 characters.',
            'issue_category.required' => 'Please select an issue category.',
            'issue_category.in' => 'Invalid issue category.',
            'evidence_photos.max' => 'You can upload a maximum of 10 photos.',
            'evidence_photos.*.image' => 'Each file must be an image.',
            'evidence_photos.*.max' => 'Each photo must be less than 5MB.',
        ];
    }
}
