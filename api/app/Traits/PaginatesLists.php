<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Bounded pagination for list endpoints.
 *
 * `paginate($request->input('per_page', 15))` let any caller ask for the whole
 * table in one response (`?per_page=1000000`), which is a memory and response
 * size problem that gets worse the longer the business runs, and a negative or
 * non-numeric value surfaced as a 500 from the query builder.
 *
 * The ceiling is deliberately generous: the busiest screen in the app asks for
 * 100 rows per page.
 */
trait PaginatesLists
{
    /** Hard ceiling on rows per page. */
    protected const MAX_PER_PAGE = 100;

    /** Rows per page when the caller does not ask. */
    protected const DEFAULT_PER_PAGE = 15;

    /**
     * Validate and return the page size to use.
     */
    protected function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        $request->validate([
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:'.self::MAX_PER_PAGE,
            ],
        ]);

        return $request->integer('per_page', $default);
    }
}
