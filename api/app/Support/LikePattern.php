<?php

namespace App\Support;

/**
 * Prepares user text for a SQL `LIKE` comparison.
 *
 * Values are always bound as parameters, so this is not about injection. The
 * problem is that `%` and `_` are wildcards inside the pattern itself:
 *
 *   ?search=%       matches every row (a full table scan on a big list)
 *   ?search=_       matches every row with at least one character
 *   ?search=50%     does not find the product literally called "50%"
 *
 * Escaping the wildcards keeps a customer's search for "50%" meaning "50%".
 *
 * The escape character is `!` rather than the conventional backslash because
 * backslash escaping is not portable: MySQL honours it implicitly, SQLite only
 * honours an explicit `ESCAPE` clause and rejects backslash as the escape
 * character. `!` behaves the same on both, so the behaviour the test suite
 * exercises is the behaviour production runs.
 *
 * Every built pattern must be used with {@see self::SQL_ESCAPE}, e.g.
 *
 *     $query->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($term)]);
 */
class LikePattern
{
    /** Character that neutralises the next LIKE wildcard. */
    public const ESCAPE = '!';

    /** SQL fragment that tells the database which escape character to use. */
    public const SQL_ESCAPE = "ESCAPE '!'";

    /**
     * Build a `contains` pattern, e.g. `%term%`.
     */
    public static function contains(?string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    /**
     * Build a `starts with` pattern, e.g. `term%`.
     */
    public static function startsWith(?string $term): string
    {
        return self::escape($term).'%';
    }

    /**
     * Escape the LIKE metacharacters in a term.
     *
     * The escape character goes first, otherwise it would double-escape the
     * escapes we add after it.
     */
    public static function escape(?string $term): string
    {
        $term = (string) $term;

        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $term
        );
    }
}
