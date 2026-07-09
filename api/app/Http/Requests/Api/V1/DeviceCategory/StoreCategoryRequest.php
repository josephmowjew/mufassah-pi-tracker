<?php

namespace App\Http\Requests\Api\V1\DeviceCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:device_categories,name'],
            'slug' => ['sometimes', 'string', 'max:100', 'unique:device_categories,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please provide the category name.',
            'name.unique' => 'A category with this name already exists.',
            'slug.unique' => 'A category with this slug already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->name && ! $this->slug) {
            $this->merge([
                'slug' => Str::slug($this->name),
            ]);
        }
    }
}
