<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Uniqueness that understands soft deletes.
 *
 * A soft-deleted row still occupies its unique index, so a plain `unique` rule
 * rejects the value — but with a message that hides the real situation: the
 * clash is with a *deleted* record the operator can simply restore. This rule
 * keeps the constraint honest and says which of the two cases it is.
 *
 * Note it must NOT be relaxed to `whereNull('deleted_at')`: excluding trashed
 * rows would let validation pass and then hit the database's unique index as a
 * 500 instead of a readable 422.
 */
class UniqueUnlessDeleted implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $conditions  extra equality constraints
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
        private readonly string $label,
        private readonly array $conditions = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::withTrashed()->where($this->column, $value);

        foreach ($this->conditions as $column => $expected) {
            $query->where($column, $expected);
        }

        $existing = $query->first();

        if ($existing === null) {
            return;
        }

        $fail($existing->trashed()
            ? "A deleted {$this->label} with this {$attribute} already exists. Restore it instead of creating a new one."
            : "A {$this->label} with this {$attribute} already exists.");
    }
}
