<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FastAPI's JSONResponse serializes with ensure_ascii=False and does not escape forward slashes.
 * PHP's json_encode escapes both by default, which made every pagination guide differ
 * ("\/groups\/labels" against "/groups/labels"). Applied once here rather than at each call site.
 */
class MatchJsonEncoding
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $response;
    }
}
