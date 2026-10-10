<?php

namespace App\Support;

/**
 * Id conversion between the SQLite storage form and the JSON form.
 *
 * mealie/db/models/_model_utils/guid.py: on SQLite every id column is CHAR(32) holding undashed
 * lowercase hex, and process_result_value turns it back into a uuid.UUID, so responses carry the
 * dashed 8-4-4-4-12 form.
 */
class Guid
{
    /** Storage form: 32 lowercase hex characters, no dashes. Null when the input is not a UUID. */
    public static function pack(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $hex = strtolower(str_replace('-', '', trim($value)));

        return preg_match('/^[0-9a-f]{32}$/', $hex) === 1 ? $hex : null;
    }

    /** JSON form: dashed lowercase UUID. Null when the input is not 32 hex characters. */
    public static function dash(?string $value): ?string
    {
        $hex = self::pack($value);
        if ($hex === null) {
            return null;
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    /**
     * Whether a value is a well-formed v4 UUID.
     *
     * FastAPI parameters typed UUID4 are validated before the handler runs, and a value that is not
     * a v4 UUID is a 422 rather than a 404. Pydantic checks the version nibble, so a syntactically
     * valid UUID of another version is rejected too.
     */
    public static function isV4(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }
}
