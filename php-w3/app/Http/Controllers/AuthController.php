<?php

namespace App\Http\Controllers;

use App\Auth\Jwt;
use App\Support\CurrentUser;
use App\Support\Errors;
use App\Support\Guid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/auth/auth.py */
class AuthController extends Controller
{
    private const MAX_LOGIN_ATTEMPTS = 5;   // settings.py:248

    private const LOCKOUT_HOURS = 24;       // settings.py:249

    /**
     * auth.py:134 — form login.
     *
     * MealieAuthToken (auth.py:119) is a plain pydantic BaseModel, so its keys stay snake_case:
     * access_token / token_type / expires_in.
     */
    public function token(Request $request): JsonResponse
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $rememberMe = filter_var($request->input('remember_me', false), FILTER_VALIDATE_BOOLEAN);

        $user = $this->tryGetUser($username);

        if (! $user) {
            // credentials_provider.py:58-63 — the hash is computed anyway so that a missing user
            // costs the same time as a wrong password.
            password_verify('abc123cba321', '$2b$12$JdHtJOlkPFwyxdjdygEzPOtYmdQF5/R5tHxw5Tq8pxjubyLqdIX5i');

            return Errors::detail(401, 'Unauthorized');
        }

        // credentials_provider.py:36-41 — an LDAP or OIDC account cannot log in this way.
        if (($user->auth_method ?? 'MEALIE') !== 'MEALIE') {
            password_verify('abc123cba321', '$2b$12$JdHtJOlkPFwyxdjdygEzPOtYmdQF5/R5tHxw5Tq8pxjubyLqdIX5i');

            return Errors::detail(401, 'Unauthorized');
        }

        // credentials_provider.py:43-44 — checked before the password, so a locked account cannot
        // be probed.
        if ((int) $user->login_attemps >= self::MAX_LOGIN_ATTEMPTS || $this->isLocked($user)) {
            return Errors::detail(423, 'User is locked out');
        }

        if (! password_verify($password, (string) $user->password)) {
            $attempts = (int) $user->login_attemps + 1;
            $update = ['login_attemps' => $attempts];
            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $update['locked_at'] = gmdate('Y-m-d H:i:s.u');
            }
            DB::connection('mealie')->table('users')->where('id', $user->id)->update($update);

            return Errors::detail(401, 'Unauthorized');
        }

        if ((int) $user->login_attemps !== 0) {
            DB::connection('mealie')->table('users')->where('id', $user->id)->update(['login_attemps' => 0]);
        }

        return $this->issue($request, Guid::dash($user->id), $rememberMe);
    }

    /**
     * auth.py:167 — OIDC is never configured on this deployment, so the handler never reaches the
     * redirect: oauth is None at import time (auth.py:32-33) and it raises 500.
     */
    public function oauth(): JsonResponse
    {
        return Errors::detail(500, 'Could not initialize OAuth client');
    }

    /** auth.py:191 — same guard, same 500. */
    public function oauthCallback(): JsonResponse
    {
        return Errors::detail(500, 'Could not initialize OAuth client');
    }

    /** auth.py:226 — this guard answers 404, not 500. The difference is in the Python. */
    public function nativeConfig(): JsonResponse
    {
        return Errors::detail(404, 'OIDC is not configured');
    }

    /**
     * auth.py:244 — the body is validated by FastAPI before the handler runs, so a malformed body
     * is a 422 and only a well-formed one reaches the 404.
     */
    public function nativeToken(Request $request): JsonResponse
    {
        // An absent body and a body of the wrong shape are different errors in pydantic.
        if ($request->getContent() === '') {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body',), 'msg': 'Field required', 'input': None}");
        }

        $body = $request->json()->all();

        if (! is_array($body) || array_is_list($body)) {
            return Errors::unprocessable("1 validation error: {'type': 'model_attributes_type', 'loc': ('body',), 'msg': 'Input should be a valid dictionary or object to extract fields from'}");
        }

        foreach (['code', 'code_verifier', 'redirect_uri'] as $field) {
            if (! isset($body[$field]) || ! is_string($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': None}");
            }
        }

        return Errors::detail(404, 'OIDC is not configured');
    }

    /**
     * auth.py:301 — on UserAPIRouter, so the middleware has already resolved the user or returned
     * 401. A long-lived API token may not be exchanged for a session token (auth.py:318-324).
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->attributes->get('mealie_user');
        $claims = Jwt::decode(CurrentUser::token($request));

        if ($claims === null) {
            return Errors::credentials();
        }

        if (! empty($claims['long_token'])) {
            return Errors::detail(400, 'API tokens cannot be exchanged for a session token');
        }

        return $this->issue($request, Guid::dash($user->id), (bool) ($claims['rme'] ?? false));
    }

    /** auth.py:332 — clears the cookie with the same attributes it was set with. */
    public function logout(Request $request): JsonResponse
    {
        $response = new JsonResponse(['message' => 'Logged out']); // lang/messages/en-US.json:86
        $attrs = $this->cookieAttrs($request);

        $response->headers->setCookie($this->cookie($request, '', 0, $attrs));

        return $response;
    }

    /** auth_provider.py:24-31 plus tokens.py:11-30 — payload, lifetime and the session cookie. */
    private function issue(Request $request, string $subject, bool $rememberMe): JsonResponse
    {
        $seconds = ((int) config('mealie.token_time')) * 3600;
        $now = time();

        $token = Jwt::encode([
            'sub' => $subject,
            'rme' => $rememberMe,
            'iss' => Jwt::ISS,
            'iat' => $now,
            'exp' => $now + $seconds,
        ], Jwt::secret());

        $response = new JsonResponse([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $seconds,
        ]);

        $response->headers->setCookie(
            $this->cookie($request, $token, $rememberMe ? $seconds : 0, $this->cookieAttrs($request))
        );

        return $response;
    }

    /**
     * auth.py:82-97 — secure only over HTTPS (or the first entry of x-forwarded-proto); SameSite
     * none plus partitioned only when the client flags an embedded deployment over HTTPS.
     */
    private function cookieAttrs(Request $request): array
    {
        $forwarded = strtolower(trim(explode(',', (string) $request->header('x-forwarded-proto', ''))[0]));
        $secure = $request->isSecure() || $forwarded === 'https';
        $embedded = $secure && strtolower((string) $request->header('x-mealie-embedded', '')) === 'true';

        return ['secure' => $secure, 'samesite' => $embedded ? 'none' : 'lax', 'partitioned' => $embedded];
    }

    private function cookie(Request $request, string $value, int $maxAge, array $attrs): \Symfony\Component\HttpFoundation\Cookie
    {
        return new \Symfony\Component\HttpFoundation\Cookie(
            CurrentUser::COOKIE,
            $value,
            $maxAge > 0 ? time() + $maxAge : 0,
            '/',
            null,
            $attrs['secure'],
            false,            // auth.py:114 — the SPA reads this cookie, so it is not HttpOnly
            false,
            $attrs['samesite'],
            $attrs['partitioned'],
        );
    }

    /** auth_provider.py:34-45 — username first, then email, both case-insensitive. */
    private function tryGetUser(string $username): ?object
    {
        if ($username === '') {
            return null;
        }

        $db = DB::connection('mealie');
        $needle = mb_strtolower($username);

        return $db->table('users')->whereRaw('lower(username) = ?', [$needle])->first()
            ?? $db->table('users')->whereRaw('lower(email) = ?', [$needle])->first();
    }

    /** mealie/schema/user/user.py:228-233 — the lock expires SECURITY_USER_LOCKOUT_TIME after it was set. */
    private function isLocked(object $user): bool
    {
        if (empty($user->locked_at)) {
            return false;
        }

        return strtotime((string) $user->locked_at.' UTC') + self::LOCKOUT_HOURS * 3600 > time();
    }
}
