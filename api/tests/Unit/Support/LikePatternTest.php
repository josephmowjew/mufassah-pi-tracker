<?php

use App\Support\LikePattern;

it('wraps a plain term for a contains match', function () {
    expect(LikePattern::contains('abc'))->toBe('%abc%');
    expect(LikePattern::startsWith('abc'))->toBe('abc%');
});

it('escapes the percent wildcard', function () {
    expect(LikePattern::contains('%'))->toBe('%!%%');
    expect(LikePattern::contains('50%'))->toBe('%50!%%');
});

it('escapes the underscore wildcard', function () {
    expect(LikePattern::contains('_'))->toBe('%!_%');
    expect(LikePattern::contains('a_b'))->toBe('%a!_b%');
});

it('escapes the escape character before the wildcards', function () {
    // If this ran after the wildcard replacements, `!` would double-escape them.
    expect(LikePattern::contains('!'))->toBe('%!!%');
    expect(LikePattern::contains('a!b'))->toBe('%a!!b%');
});

it('leaves normal text untouched', function () {
    expect(LikePattern::contains('Tecno Spark 20'))->toBe('%Tecno Spark 20%');
    expect(LikePattern::contains('+265 999 123 456'))->toBe('%+265 999 123 456%');
});

it('keeps backslashes literal', function () {
    // Backslash is an ordinary character with this escape character.
    expect(LikePattern::contains('a\\b'))->toBe('%a\\b%');
});

it('treats null and empty input as match-everything, like before', function () {
    expect(LikePattern::contains(null))->toBe('%%');
    expect(LikePattern::contains(''))->toBe('%%');
});

it('ships an ESCAPE clause that names its own escape character', function () {
    expect(LikePattern::SQL_ESCAPE)->toBe("ESCAPE '!'");
});
