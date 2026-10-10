<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\Guid;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/validators/validators.py — five anonymous lookups.
 *
 * Every one returns ValidationResponse (mealie/schema/response/validation.py:4), which is a plain
 * pydantic BaseModel, not a MealieModel: the body is exactly {"valid": bool}, not camelCased and
 * not wrapped. valid is true when nothing matching was found.
 */
class ValidatorsController extends Controller
{
    /** validators.py:14 — users.get_one(name, "username", any_case=True). */
    public function userName(Request $request): JsonResponse
    {
        if (($name = $request->query('name')) === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', 'name'), 'msg': 'Field required', 'input': None}");
        }

        return $this->valid(! $this->existsCaseInsensitive('users', 'username', (string) $name));
    }

    /** validators.py:22 — users.get_one(email, "email", any_case=True). */
    public function userEmail(Request $request): JsonResponse
    {
        if (($email = $request->query('email')) === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', 'email'), 'msg': 'Field required', 'input': None}");
        }

        return $this->valid(! $this->existsCaseInsensitive('users', 'email', (string) $email));
    }

    /** validators.py:30 — groups.get_by_name, an exact match (repository_group.py:53). */
    public function group(Request $request): JsonResponse
    {
        if (($name = $request->query('name')) === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', 'name'), 'msg': 'Field required', 'input': None}");
        }

        $found = DB::connection('mealie')->table('groups')->where('name', (string) $name)->exists();

        return $this->valid(! $found);
    }

    /**
     * validators.py:38 — always a 500.
     *
     * The route builds its repositories with group_id=None (validators.py:41), and
     * HouseholdRepository.get_by_name (mealie/repos/repository_household.py:69-70) opens with
     *     if not self.group_id: raise Exception("group_id not set")
     * The exception is unhandled, so every call to this path fails the same way whatever the name
     * is. This is an upstream defect in Mealie that the rewrite reproduces deliberately; it is not
     * a bug in this handler. Starlette renders it as plain text, not a JSON detail object.
     */
    public function household(Request $request)
    {
        if ($request->query('name') === null) {
            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', 'name'), 'msg': 'Field required', 'input': None}");
        }

        return Errors::serverError();
    }

    /** validators.py:46 — recipes.get_by_slug(group_id, slugify(name)); group_id is typed UUID4. */
    public function recipe(Request $request): JsonResponse
    {
        $groupId = $request->query('group_id');
        $name = $request->query('name');

        if ($groupId === null || $name === null) {
            $missing = $groupId === null ? 'group_id' : 'name';

            return Errors::unprocessable("1 validation error: {'type': 'missing', 'loc': ('query', '$missing'), 'msg': 'Field required', 'input': None}");
        }

        if (! Guid::isV4((string) $groupId)) {
            return Errors::unprocessable("1 validation error: {'type': 'uuid_parsing', 'loc': ('query', 'group_id'), 'msg': 'Input should be a valid UUID', 'input': '$groupId'}");
        }

        $found = DB::connection('mealie')->table('recipes')
            ->where('group_id', Guid::pack((string) $groupId))
            ->where('slug', Slug::make((string) $name))
            ->exists();

        return $this->valid(! $found);
    }

    /** any_case=True in the repository is a lowercase comparison on both sides. */
    private function existsCaseInsensitive(string $table, string $column, string $value): bool
    {
        return DB::connection('mealie')->table($table)
            ->whereRaw("lower($column) = ?", [mb_strtolower($value)])->exists();
    }

    private function valid(bool $valid): JsonResponse
    {
        return new JsonResponse(['valid' => $valid]);
    }
}
