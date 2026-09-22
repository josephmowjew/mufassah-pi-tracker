<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // A soft-deleted branch keeps its `code` in the unique index, and MySQL has no
            // partial unique index. BranchController::store restores the trashed row rather
            // than inserting, so a clash with a *deleted* branch must pass validation here -
            // otherwise the code would be blocked forever. A clash with a live branch still
            // fails, and the index is never violated because the insert becomes an update.
            'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')->whereNull('deleted_at')],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['boolean'],
        ];
    }
}
