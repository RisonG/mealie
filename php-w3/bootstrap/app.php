<?php

use App\Http\Middleware\MatchJsonEncoding;
use App\Http\Middleware\MealieAuth;
use App\Support\Errors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['mealie' => MealieAuth::class]);

        // Match FastAPI's JSON encoding on every API response.
        $middleware->api(prepend: [MatchJsonEncoding::class]);

        // FastAPI has no CSRF token and no session for the API; nothing here should add one.
        $middleware->api(remove: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Order matters. The specific HTTP exceptions are rendered first so that a 401 or 403
        // raised inside the framework keeps its status; a catch-all Throwable renderer placed
        // ahead of them turns every one of them into a 500.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return $request->is('api/*') ? Errors::notFound() : null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            return $request->is('api/*') ? Errors::methodNotAllowed() : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();
            $detail = $e->getMessage() !== '' ? $e->getMessage() : (Response::$statusTexts[$status] ?? 'Error');

            return Errors::detail($status, $detail, $e->getHeaders());
        });

        // Anything else. Starlette answers an unhandled exception with a plain-text body, not a
        // JSON detail object — see /api/validators/household, which is always a 500 by design.
        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? Errors::serverError() : null;
        });
    })->create();
