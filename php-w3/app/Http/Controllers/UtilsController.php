<?php

namespace App\Http\Controllers;

use App\Auth\Jwt;
use App\Support\Errors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * mealie/routes/utility_routes.py — mounted at /api/utils directly from mealie/app.py:150, not
 * through mealie/routes/__init__.py.
 *
 * There is no user dependency here. The file token is the authorization, which is why step 2 below
 * checks for the "file" claim rather than trusting a valid signature: session and API tokens are
 * signed with the same secret and would otherwise decode fine.
 */
class UtilsController extends Controller
{
    public function download(Request $request)
    {
        $token = $request->query('token');

        // dependencies.py:187-188
        if (! $token) {
            return Errors::badRequest();
        }

        // dependencies.py:190-200
        $claims = Jwt::decode((string) $token);
        if ($claims === null || ! isset($claims['file']) || ! is_string($claims['file'])) {
            return Errors::detail(401, 'could not validate file token');
        }

        $path = $claims['file'];

        // dependencies.py:202-203 — existence is checked before the allow-list, so a path that is
        // both outside the allowed directories and missing returns here. Same status either way.
        if (! file_exists($path)) {
            return Errors::badRequest();
        }

        $resolved = realpath($path);
        $dataDir = (string) config('mealie.data_dir');
        $allowed = [realpath($dataDir.'/backups'), realpath($dataDir.'/groups')];

        // utility_routes.py:25-26
        $inside = false;
        foreach (array_filter($allowed) as $dir) {
            if ($resolved !== false && str_starts_with($resolved, $dir.DIRECTORY_SEPARATOR)) {
                $inside = true;
                break;
            }
        }
        if (! $inside) {
            return Errors::badRequest();
        }

        // utility_routes.py:28-29
        if (! is_file($resolved)) {
            return Errors::badRequest();
        }

        return new BinaryFileResponse($resolved, 200, ['Content-Type' => 'application/octet-stream'], false, 'attachment');
    }
}
