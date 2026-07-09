<?php

namespace App\Http\Requests\Api\V1\Warranty;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarrantyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'coverage_details' => ['sometimes', 'string', 'max:1000'],
            'terms_conditions' => ['sometimes', 'array'],
            'provider' => ['sometimes', 'string', 'max:100'],
            'notes' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
