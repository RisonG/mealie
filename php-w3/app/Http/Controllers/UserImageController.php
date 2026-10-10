<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\UserPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** mealie/routes/users/images.py — UserAPIRouter(prefix=""), included with prefix="/users". */
class UserImageController extends Controller
{
    public function update(Request $request, string $id): JsonResponse
    {
        if (! Guid::isV4($id)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('path', 'id'), 'msg': 'Input should be a valid UUID', 'input': '$id'}");
        }

        // images.py:23 — the file field is required, so a request without it is a 422.
        if (! $request->hasFile('profile')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'profile'), 'msg': 'Field required', 'input': None}");
        }

        // images.py:27 passes the caller as both arguments, so only the identity check can fire.
        if ($denied = UserPermissions::selfOnly($request->attributes->get('mealie_user'), $id)) {
            return $denied;
        }

        /*
         * Python converts the upload to WebP, writes <data>/users/<id>/profile.webp and patches
         * users.cache_key. Neither the Pillow encoding nor the write is implemented — see the
         * contract, §2.10 "Not implemented". Stopping here also keeps the round from mutating the
         * shared database.
         */
        return Errors::detail(501, 'Profile image upload is not implemented in this rewrite');
    }
}
