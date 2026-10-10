<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The error bodies FastAPI produces, measured against the oracle on 2026-10-10.
 *
 * 404 {"detail":"Not Found"} / 405 {"detail":"Method Not Allowed"} /
 * 401 {"detail":"Could not validate credentials"} with www-authenticate: Bearer /
 * 422 {"status_code":422,"message":...,"data":null} (the debug handler at
 * mealie/routes/handlers.py:18-29, registered only when PRODUCTION is false) /
 * 500 Starlette's plain-text "Internal Server Error" for an unhandled exception.
 */
class Errors
{
    public static function detail(int $status, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse(['detail' => $detail], $status, $headers);
    }

    public static function notFound(): JsonResponse
    {
        return self::detail(404, 'Not Found');
    }

    /**
     * A request body that is not a JSON object.
     *
     * Every route whose handler takes a pydantic model rejects a scalar or a list with this 422,
     * and it happens during request validation — so it precedes permission checks, which live
     * inside the handler.
     */
    public static function bodyNotAnObject(): JsonResponse
    {
        return self::unprocessable("1 validation error: {'type': 'model_attributes_type', 'loc': ('body',), 'msg': 'Input should be a valid dictionary or object to extract fields from'}");
    }

    /** True when the request carried a body that is not a JSON object. */
    public static function hasNonObjectBody(\Illuminate\Http\Request $request): bool
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);

        return ! is_array($decoded) || array_is_list($decoded) && $decoded !== [];
    }

    /**
     * The 404 raised by HttpRepo.get_one (mealie/routes/_base/mixins.py:110-113) — an ErrorResponse
     * object under detail, not the bare "Not Found" string an unrouted path gets.
     */
    public static function repoNotFound(): JsonResponse
    {
        return new JsonResponse(['detail' => ['message' => 'Not found.', 'error' => true, 'exception' => null]], 404);
    }

    public static function methodNotAllowed(): JsonResponse
    {
        return self::detail(405, 'Method Not Allowed');
    }

    /** mealie/core/dependencies/dependencies.py:28-32 — detail and header both. */
    public static function credentials(): JsonResponse
    {
        return self::detail(401, 'Could not validate credentials', ['WWW-Authenticate' => 'Bearer']);
    }

    /** get_admin_user raises HTTPException(403) with no detail; FastAPI fills the status phrase. */
    public static function forbidden(): JsonResponse
    {
        return self::detail(403, 'Forbidden');
    }

    public static function badRequest(string $detail = 'Bad Request'): JsonResponse
    {
        return self::detail(400, $detail);
    }

    public static function unprocessable(string $message): JsonResponse
    {
        return new JsonResponse(['status_code' => 422, 'message' => $message, 'data' => null], 422);
    }

    /** Python returns a body of literally `null`; JsonResponse(null) would render `{}`. */
    public static function nullBody(): JsonResponse
    {
        return (new JsonResponse())->setJson('null');
    }

    /**
     * An unhandled server error. Starlette returns text/plain here, not a JSON detail object, which
     * is why /api/validators/household's 500 is not JSON.
     */
    public static function serverError(): Response
    {
        return new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
