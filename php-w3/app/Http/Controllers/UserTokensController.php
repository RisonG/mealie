<?php

namespace App\Http\Controllers;

use App\Auth\Jwt;
use App\Support\Dates;
use App\Support\Errors;
use App\Support\Guid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/users/api_tokens.py — UserAPIRouter(prefix="/users"), included without a prefix. */
class UserTokensController extends Controller
{
    /** api_tokens.py:21 — 201 with LongLiveTokenCreateResponse (user.py:44). */
    public function create(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! is_array($body) || ! isset($body['name']) || ! is_string($body['name'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'name'), 'msg': 'Field required', 'input': None}");
        }

        $user = $request->attributes->get('mealie_user');
        $integrationId = $body['integrationId'] ?? $body['integration_id'] ?? 'generic';

        // api_tokens.py:28-36 — the claims are snake_case here, and the lifetime is 1825 days.
        $now = time();
        $token = Jwt::encode([
            'long_token' => true,
            'id' => Guid::dash($user->id),
            'name' => $body['name'],
            'integration_id' => $integrationId,
            'iss' => Jwt::ISS,
            'iat' => $now,
            'exp' => $now + 1825 * 86400,
        ], Jwt::secret());

        $createdAt = gmdate('Y-m-d H:i:s.u');
        $id = DB::connection('mealie')->table('long_live_tokens')->insertGetId([
            'name' => $body['name'],
            'token' => $token,
            'user_id' => $user->id,
            'created_at' => $createdAt,
            'update_at' => $createdAt,
        ]);

        return new JsonResponse([
            'name' => $body['name'],
            'id' => (int) $id,
            'createdAt' => Dates::out($createdAt),
            'token' => $token,
        ], 201);
    }

    /**
     * api_tokens.py:49 — token_id is an int, not a UUID, because long_live_tokens.id is an INTEGER
     * primary key. The 404 detail is a bare string, unlike the ErrorResponse objects elsewhere.
     */
    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        if (! preg_match('/^-?\d+$/', $tokenId)) {
            return Errors::unprocessable("1 validation error: {'type': 'int_parsing', 'loc': ('path', 'token_id'), 'msg': 'Input should be a valid integer, unable to parse string as an integer', 'input': '$tokenId'}");
        }

        $row = DB::connection('mealie')->table('long_live_tokens')->where('id', (int) $tokenId)->first();

        if (! $row) {
            return Errors::detail(404, "Could not locate token with id '$tokenId' in database");
        }

        $user = $request->attributes->get('mealie_user');
        if ($row->user_id !== $user->id) {          // api_tokens.py:59 — ownership is by user id
            return Errors::forbidden();
        }

        DB::connection('mealie')->table('long_live_tokens')->where('id', (int) $tokenId)->delete();

        return new JsonResponse(['tokenDelete' => $row->name]);
    }
}
