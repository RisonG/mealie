<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** mealie/routes/groups/controller_migrations.py:30 — multipart, UserAPIRouter. */
class GroupMigrationsController extends Controller
{
    /** The SupportedMigrations enum values, checked before the handler as FastAPI would. */
    private const SUPPORTED = [
        'chowdown', 'copymethat', 'mealie_alpha', 'nextcloud', 'paprika', 'tandoor', 'plantoeat',
    ];

    public function store(Request $request): JsonResponse
    {
        $type = $request->input('migration_type');

        if ($type === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'migration_type'), 'msg': 'Field required', 'input': None}");
        }

        if (! in_array($type, self::SUPPORTED, true)) {
            return Errors::unprocessable("1 validation error: {'type': 'enum', 'loc': ('body', 'migration_type'), 'msg': 'Input should be a valid enumeration member', 'input': '$type'}");
        }

        if (! $request->hasFile('archive')) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('body', 'archive'), 'msg': 'Field required', 'input': None}");
        }

        /*
         * Every migrator (Chowdown, CopyMeThat, Mealie alpha, Nextcloud, Paprika, Tandoor,
         * PlanToEat) is out of scope — see the contract, §3.17. The multipart validation above is
         * real; nothing is unpacked and no recipe is written.
         */
        return Errors::detail(501, 'Data migration is not implemented in this rewrite');
    }
}
