<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** mealie/routes/groups/controller_seeder.py — authenticated, no permission check. */
class GroupSeederController extends Controller
{
    /** :28 */
    public function foods(Request $request): JsonResponse
    {
        return $this->seed($request);
    }

    /** :32 */
    public function units(Request $request): JsonResponse
    {
        return $this->seed($request);
    }

    private function seed(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        $body = $request->json()->all();
        if (! is_array($body) || ! isset($body['locale']) || ! is_string($body['locale'])) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'locale'), 'msg': 'Field required', 'input': None}");
        }

        /*
         * SeederService bulk-inserts the packaged food or unit list for a locale — hundreds of rows
         * into the shared database. Not implemented (contract §3.26-3.27); reported rather than
         * answered with the SuccessResponse that would claim seeding had happened.
         */
        return Errors::detail(501, 'Seeding is not implemented in this rewrite');
    }
}
