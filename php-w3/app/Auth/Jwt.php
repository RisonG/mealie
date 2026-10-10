<?php

namespace App\Auth;

/**
 * HS256 tokens interchangeable with the Python backend's.
 *
 * Source: mealie/core/security/tokens.py (ALGORITHM = "HS256", ISS = "mealie") and
 * mealie/core/dependencies/dependencies.py, which decodes with settings.SECRET.
 *
 * compx574/hurl/run.sh calls Jwt::encode() and Jwt::secret() statically, with the payload as the
 * first argument and the secret as the second. Both names and both signatures are fixed by it.
 */
class Jwt
{
    public const ALGORITHM = 'HS256';

    public const ISS = 'mealie';

    public static function secret(): string
    {
        return (string) config('mealie.secret');
    }

    public static function encode(array $payload, string $secret): string
    {
        $header = ['alg' => self::ALGORITHM, 'typ' => 'JWT'];
        $segments = [
            self::b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signing = implode('.', $segments);
        $segments[] = self::b64UrlEncode(hash_hmac('sha256', $signing, $secret, true));

        return implode('.', $segments);
    }

    /**
     * Returns the claims, or null when the token is absent, malformed, badly signed, or expired.
     *
     * Python raises PyJWTError for all of those and every caller turns that into a 401, so one
     * null return covers the same ground.
     */
    public static function decode(?string $token, ?string $secret = null): ?array
    {
        if (! $token) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$head, $body, $sig] = $parts;

        $header = json_decode(self::b64UrlDecode($head) ?: '', true);
        if (! is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            return null;
        }

        $expected = hash_hmac('sha256', $head.'.'.$body, $secret ?? self::secret(), true);
        if (! hash_equals($expected, self::b64UrlDecode($sig) ?: '')) {
            return null;
        }

        $claims = json_decode(self::b64UrlDecode($body) ?: '', true);
        if (! is_array($claims)) {
            return null;
        }

        // PyJWT verifies exp and nbf by default; it does not require them to be present.
        $now = time();
        if (isset($claims['exp']) && $now >= (int) $claims['exp']) {
            return null;
        }
        if (isset($claims['nbf']) && $now < (int) $claims['nbf']) {
            return null;
        }

        return $claims;
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
