<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Stand-in for python-slugify's slugify(), used by mealie/routes/validators/validators.py:50 to turn
 * a recipe name into the slug stored in recipes.slug.
 *
 * Both lowercase, transliterate to ASCII, collapse every run of non-alphanumerics into a single
 * hyphen and strip leading/trailing hyphens. They can still differ on exotic transliterations;
 * the contract records this as an approximation rather than a guarantee.
 */
class Slug
{
    public static function make(string $value): string
    {
        return Str::slug($value, '-');
    }
}
