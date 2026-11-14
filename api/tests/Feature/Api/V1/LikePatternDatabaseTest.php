<?php

use App\Support\LikePattern;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unit tests pin the pattern strings; this proves the patterns actually
 * change what the database matches. It runs on whatever driver the suite uses
 * (SQLite), so the escape character is chosen to behave the same there and on
 * the MySQL production database.
 */
it('matches literals instead of wildcards in the database', function () {
    $table = 'like_pattern_probe';

    Schema::dropIfExists($table);
    Schema::create($table, function ($blueprint) {
        $blueprint->string('name')->nullable();
    });

    try {
        DB::table($table)->insert([
            ['name' => 'Alpha'],
            ['name' => '50%'],
            ['name' => 'a_b'],
            ['name' => 'a%b'],
            ['name' => 'beta'],
        ]);

        $matches = fn (string $term) => DB::table($table)
            ->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($term)])
            ->pluck('name')->all();

        expect($matches('Alp'))->toBe(['Alpha']);
        expect($matches('bet'))->toBe(['beta']);

        // A bare "%" used to match every row; escaped it only matches a literal percent.
        expect($matches('%'))->toBe(['50%', 'a%b']);
        // A bare "_" used to match every row with a character; escaped only literals.
        expect($matches('_'))->toBe(['a_b']);

        // These used to match extra rows through the wildcard.
        expect($matches('50%'))->toBe(['50%']);
        expect($matches('a_b'))->toBe(['a_b']);

        // The escape character itself must be searchable.
        expect($matches('!'))->toBe([]);
    } finally {
        Schema::dropIfExists($table);
    }
});
