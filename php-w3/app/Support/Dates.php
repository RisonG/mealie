<?php

namespace App\Support;

/**
 * SQLite stores datetimes as "Y-m-d H:i:s[.u]" with no zone. Pydantic serializes a naive datetime
 * as ISO 8601 without an offset, so the only change needed is the date/time separator.
 */
class Dates
{
    public static function out(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace(' ', 'T', trim($value));
    }
}
