<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** mealie/routes/users/forgot_password.py — both routes anonymous. */
class UserPasswordController extends Controller
{
    /**
     * forgot_password.py:13 — the oracle answers 200 with a body of literally null for an address
     * with no matching user, and writes nothing.
     *
     * For an address that does match, Python creates a password_reset_tokens row and sends mail.
     * Neither is implemented here; the contract records this as the one place in round 2 where the
     * PHP deliberately does less than the Python.
     */
    public function forgot(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! is_array($body) || ! isset($body['email']) || ! is_string($body['email'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'email'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::nullBody();
    }

    /** forgot_password.py:24 — an unknown token is 400 with a bare string detail. */
    public function reset(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        foreach (['token', 'email', 'password', 'passwordConfirm'] as $field) {
            if (! isset($body[$field]) || ! is_string($body[$field])) {
                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$field'), 'msg': 'Field required', 'input': None}");
            }
        }

        $row = DB::connection('mealie')->table('password_reset_tokens')->where('token', $body['token'])->first();

        if (! $row) {
            return Errors::detail(400, 'Invalid token');
        }

        // The successful reset writes users.password; not implemented (contract §2.9).
        return Errors::detail(501, 'Password reset completion is not implemented in this rewrite');
    }
}
