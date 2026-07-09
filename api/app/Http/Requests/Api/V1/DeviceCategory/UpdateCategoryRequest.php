<?php

namespace App\Http\Requests\Api\V1\DeviceCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['super_admin', 'store_admin']);
    }

    public function rules(): array
    {
        $categoryId = $this->route('device_category')?->id ?? $this->route('id');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('device_categories', 'name')->ignore($categoryId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->name) {
            $this->merge([
                'slug' => Str::slug($this->name),
            ]);
        }
    }
}
