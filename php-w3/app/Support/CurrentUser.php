<?php

namespace App\Support;

use App\Auth\Jwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the caller the way mealie/core/dependencies/dependencies.py does.
 *
 * get_auth_token (dependencies.py:76-89) takes the bearer token from the Authorization header and
 * falls back to the mealie.access_token cookie. get_current_user (dependencies.py:102-138) then
 * decodes it, honours a long_token claim, loads the user by the sub claim, and rejects tokens
 * issued before the user's tokens_valid_after.
 */
class CurrentUser
{
    public const COOKIE = 'mealie.access_token';

    /** The raw token for this request, or '' — dependencies.py:76-89. */
    public static function token(Request $request): string
    {
        $header = $request->header('Authorization', '');
        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
            return trim($m[1]);
        }

        return (string) ($request->cookie(self::COOKIE) ?? '');
    }

    /**
     * The user row for this request, or null when the token does not resolve to one.
     *
     * Every null here is a 401 in Python, including the long-lived-token IndexError at
     * dependencies.py:173-175.
     */
    public static function resolve(Request $request): ?object
    {
        $token = self::token($request);
        $claims = Jwt::decode($token);
        if ($claims === null) {
            return null;
        }

        // dependencies.py:111-112 — a long_token claim is looked up in the token table instead of
        // being trusted on its own, so revoking the row revokes the credential.
        if (array_key_exists('long_token', $claims) && $claims['long_token'] !== null) {
            $userId = Guid::pack((string) ($claims['id'] ?? ''));
            if ($userId === null) {
                return null;
            }

            $row = DB::connection('mealie')->table('long_live_tokens')
                ->where('token', $token)->where('user_id', $userId)->first();

            return $row ? self::userById($userId) : null;
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '') {
            return null;
        }

        $user = self::userById(Guid::pack($sub) ?? '');
        if ($user === null) {
            return null;
        }

        // dependencies.py:131-136 — a password change invalidates everything issued before it, and
        // a token with no iat fails closed because it necessarily predates the change.
        if ($user->tokens_valid_after !== null && $user->tokens_valid_after !== '') {
            $validAfter = strtotime((string) $user->tokens_valid_after.' UTC');
            $issuedAt = $claims['iat'] ?? null;
            if ($issuedAt === null || (int) $issuedAt < $validAfter) {
                return null;
            }
        }

        return $user;
    }

    private static function userById(string $packedId): ?object
    {
        if ($packedId === '') {
            return null;
        }

        return DB::connection('mealie')->table('users')->where('id', $packedId)->first();
    }
}
