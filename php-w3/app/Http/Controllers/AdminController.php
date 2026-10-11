<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;

/**
 * mealie/routes/admin/ — all 38 routes, behind AdminAPIRouter(prefix="/admin").
 *
 * The whole package inherits Depends(get_admin_user) from the router (routes/_base/routers.py:13-17),
 * which is a **router dependency**, not an in-handler check. That ordering matters and was measured:
 * FastAPI resolves it before parsing the body, so a scalar body on an admin route is a 403 with the
 * body never read, where a checks.can_organize() route would answer 422 for the same request.
 * Laravel route middleware runs in the same position, so `mealie:admin` is the faithful translation.
 *
 * What it is *not* is a blanket rule on the /api/admin prefix. Routing happens before the
 * dependency, so GET /api/admin/nonexistent is a 404 in Python, not a 403 — verified. Declaring the
 * real routes and attaching the middleware to them reproduces that; short-circuiting the prefix
 * ahead of routing does not.
 *
 * Nothing behind these routes is implemented. For the parity token every one of them is a 403 and
 * the handler is never reached, so that costs nothing here — but it means this PHP would be wrong
 * for an actual admin, which the contract and the report both say.
 */
class AdminController extends Controller
{
    public function __call($method, $parameters): JsonResponse
    {
        return Errors::detail(501, 'Admin operations are not implemented in this rewrite');
    }
}
