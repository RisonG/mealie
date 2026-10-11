<?php

namespace App\Support;

/**
 * SQLite stores these columns naive, e.g. "2026-07-30 22:36:59.914360", but
 * NaiveDateTime.process_result_value (mealie/db/models/_model_utils/datetime.py:43-50) puts UTC
 * back on every value as it is read, so pydantic serializes it with a Z:
 * "2026-07-30T22:36:59.914360Z".
 *
 * Python's isoformat drops the microseconds when they are zero, so ".000000" becomes nothing.
 */
class Dates
{
    public static function out(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = str_replace(' ', 'T', trim($value));

        if (str_ends_with($text, '.000000')) {
            $text = substr($text, 0, -7);
        }

        return $text.'Z';
    }

    /** A plain date column (mealplan.date) has no time part and no zone. */
    public static function dateOut(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return explode(' ', explode('T', trim($value))[0])[0];
    }
}
