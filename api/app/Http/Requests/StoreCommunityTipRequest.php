<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUtcDates;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommunityTipRequest extends FormRequest
{
    use NormalizesUtcDates;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeUtcDateInput('spotted_at');
    }

    public function rules(): array
    {
        return [
            'tip_type' => ['required', 'string', 'in:sighting,location,information,recovery'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'spotted_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'spotted_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'spotted_address' => ['nullable', 'string', 'max:500'],
            'spotted_at' => ['nullable', 'date', 'before_or_equal:now'],
            'is_anonymous' => ['nullable', 'boolean'],
            'contact_preference' => ['nullable', 'string', 'in:phone,email,whatsapp,none'],
            'contact_info' => ['nullable', 'string', 'max:100'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'tip_type.required' => 'Please specify the type of tip.',
            'tip_type.in' => 'Tip type must be one of: sighting, location, information, or recovery.',
            'description.required' => 'Please provide a description of your tip.',
            'description.min' => 'Description must be at least 10 characters.',
            'description.max' => 'Description cannot exceed 2000 characters.',
            'spotted_at.before_or_equal' => 'Spotted date cannot be in the future.',
            'photos.max' => 'You can upload a maximum of 5 photos.',
        ];
    }
}
