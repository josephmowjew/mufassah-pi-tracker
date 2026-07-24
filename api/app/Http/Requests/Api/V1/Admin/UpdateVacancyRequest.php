<?php

namespace App\Http\Requests\Api\V1\Admin;

class UpdateVacancyRequest extends StoreVacancyRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['sometimes', 'required', 'string', 'max:80'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'application_email' => ['nullable', 'email', 'max:255'],
            'closing_date' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
