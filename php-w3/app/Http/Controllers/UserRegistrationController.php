<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** mealie/routes/users/registration.py — anonymous (BasePublicController). */
class UserRegistrationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! is_array($body)) {
            $body = [];
        }

        foreach (['email', 'username', 'fullName' => 'full_name', 'password', 'passwordConfirm' => 'password_confirm'] as $camel => $snake) {
            $key = is_int($camel) ? $snake : $camel;
            if (! isset($body[$key]) || ! is_string($body[$key])) {
                $loc = is_int($camel) ? $snake : $snake;

                return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', '$loc'), 'msg': 'Field required', 'input': None}");
            }
        }

        // registration.py:31-36 — password_confirm must equal password.
        if ($body['passwordConfirm'] !== $body['password']) {
            return Errors::unprocessable("1 validation error: {'type': 'value_error', 'loc': ('body', 'passwordConfirm'), 'msg': 'Value error, passwords do not match', 'input': '{$body['passwordConfirm']}'}");
        }

        $group = $body['group'] ?? null;
        $token = $body['groupToken'] ?? $body['group_token'] ?? null;

        /*
         * registration.py:38-44 — this validator runs before the handler, so a body with neither a
         * group nor a token never reaches the 403 below. The loc differs by whether the token was
         * absent or empty, which is pydantic reporting the field as it was supplied.
         */
        if (! $group && ! $token) {
            $loc = array_key_exists('groupToken', $body) || array_key_exists('group_token', $body)
                ? 'groupToken' : 'group_token';
            $input = $token === null ? 'None' : "'".$token."'";

            return Errors::unprocessable("1 validation error: {'type': 'value_error', 'loc': ('body', '$loc'), 'msg': 'Value error, group or group_token must be provided', 'input': $input}");
        }

        // registration.py:24-28
        if ((! config('mealie.allow_signup') && $token === null) || $token === '') {
            return new JsonResponse(
                ['detail' => ['message' => 'User Registration is Disabled', 'error' => true, 'exception' => null]],
                403
            );
        }

        /*
         * Beyond this point sits RegistrationService.register_user: group and household creation,
         * seed data, and the user_signup event. None of it is implemented — see the contract,
         * §2.1 "Not implemented". On this deployment ALLOW_SIGNUP is false, so a request only gets
         * here by presenting a group token, and that path is reported rather than faked.
         */
        return Errors::detail(501, 'Registration with a group token is not implemented in this rewrite');
    }
}
