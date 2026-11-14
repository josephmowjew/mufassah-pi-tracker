<?php

namespace App\Traits;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Whitelisted sorting for list endpoints.
 *
 * Every list endpoint used to pass the client's `sort`/`direction` straight to
 * the query builder. An unknown column is quoted by Laravel rather than
 * injected, but it still reaches MySQL, so `?sort=definitely_not_a_column`
 * answered with a 500. That is an error-reporting path an unauthenticated-ish
 * caller can trigger at will, and it tells the caller nothing useful.
 *
 * Each controller declares the columns it is willing to sort by; anything else
 * is rejected as a normal validation error.
 */
trait SortsLists
{
    /**
     * Validate the requested sort and return it ready for `orderBy()`.
     *
     * @param  array<int, string>  $columns  the columns this endpoint may sort by
     * @param  array{0: string, 1: string}  $default  [column, direction]
     * @return array{0: string, 1: string}
     */
    protected function resolveSort(Request $request, array $columns, array $default): array
    {
        // Some endpoints accept `sort` + `direction`, others `sort_by` +
        // `sort_order`; accepting both keeps the contract uniform for clients.
        $field = $request->input('sort', $request->input('sort_by'));
        $direction = $request->input('direction', $request->input('sort_order'));

        $request->validate([
            'sort' => ['sometimes', 'nullable', 'string', Rule::in($columns)],
            'sort_by' => ['sometimes', 'nullable', 'string', Rule::in($columns)],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
            'sort_order' => ['sometimes', 'nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        return [
            $field !== null && $field !== '' ? (string) $field : $default[0],
            $direction !== null && $direction !== '' ? strtolower((string) $direction) : $default[1],
        ];
    }

    /**
     * Apply a whitelisted sort to a query.
     *
     * @param  array<int, string>  $columns
     * @param  array{0: string, 1: string}  $default
     */
    protected function applySort(BuilderContract $query, Request $request, array $columns, array $default): void
    {
        [$field, $direction] = $this->resolveSort($request, $columns, $default);

        $query->orderBy($field, $direction);
    }
}
