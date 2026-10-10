<?php

namespace App\Http\Controllers;

use App\Support\Errors;
use App\Support\GroupSerializer;
use App\Support\Guid;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * mealie/routes/groups/controller_group_self_service.py and controller_group_households.py.
 *
 * Both controllers extend BaseUserController, so the user comes from the class rather than the
 * router, and self.repos is scoped to the caller's group (base_controllers.py:155-157).
 */
class GroupsController extends Controller
{
    /** controller_group_self_service.py:25 — no permission check. */
    public function self(Request $request): JsonResponse
    {
        $group = $this->group($request);

        return new JsonResponse(GroupSerializer::groupSummary($group));
    }

    /** controller_group_self_service.py:30 */
    public function members(Request $request): JsonResponse
    {
        $builder = DB::connection('mealie')->table('users')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);

        return new JsonResponse(Pagination::paginate(
            $builder,
            Pagination::query($request),
            fn ($u) => GroupSerializer::userSummary($u),
            '/groups/members'
        ));
    }

    /**
     * controller_group_self_service.py:37 — the handler tries UUID() and falls back to a username
     * lookup on ValueError, so any non-UUID string is treated as a username rather than rejected.
     * Note it accepts a UUID of any version here, unlike the UUID4-typed parameters elsewhere.
     */
    public function member(Request $request, string $usernameOrId): JsonResponse
    {
        $query = DB::connection('mealie')->table('users')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);

        $packed = Guid::pack($usernameOrId);
        $user = $packed !== null
            ? $query->where('id', $packed)->first()
            : $query->where('username', $usernameOrId)->first();

        if (! $user) {
            return Errors::detail(404, 'User Not Found');
        }

        return new JsonResponse(GroupSerializer::userSummary($user));
    }

    /** controller_group_self_service.py:53 — readable by any member. */
    public function preferences(Request $request): JsonResponse
    {
        $prefs = DB::connection('mealie')->table('group_preferences')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id)->first();

        return new JsonResponse(GroupSerializer::groupPreferences($prefs));
    }

    /** controller_group_self_service.py:57 — checks.can_manage(), so 403 for the parity token. */
    public function updatePreferences(Request $request): JsonResponse
    {
        if (Errors::hasNonObjectBody($request)) {
            return Errors::bodyNotAnObject();
        }

        if ($denied = $this->canManage($request)) {
            return $denied;
        }

        $groupId = $request->attributes->get('mealie_user')->group_id;
        $body = $request->json()->all();

        $update = [];
        foreach (['privateGroup' => 'private_group', 'showAnnouncements' => 'show_announcements'] as $camel => $column) {
            if (array_key_exists($camel, $body)) {
                $update[$column] = (bool) $body[$camel];
            }
        }
        if ($update) {
            DB::connection('mealie')->table('group_preferences')->where('group_id', $groupId)->update($update);
        }

        $prefs = DB::connection('mealie')->table('group_preferences')->where('group_id', $groupId)->first();

        return new JsonResponse(GroupSerializer::groupPreferences($prefs));
    }

    /**
     * controller_group_self_service.py:62 → GroupService.calculate_group_storage()
     * (group_services/group_service.py:57-74).
     *
     * Not the size of a "groups" directory: it sums get_dir_size() over
     * <data>/recipes/<recipe id> for every recipe in the group, across all households. The recipe
     * id is the dashed form, and get_dir_size (pkgs/stats/fs_stats.py:25-40) counts the directory
     * entry itself as well as its contents, which is why a group of empty recipe directories still
     * reports a few hundred bytes.
     */
    public function storage(Request $request): JsonResponse
    {
        $groupId = $request->attributes->get('mealie_user')->group_id;
        $recipeDir = config('mealie.data_dir').'/recipes';

        $used = 0;
        foreach (DB::connection('mealie')->table('recipes')->where('group_id', $groupId)->pluck('id') as $id) {
            $used += $this->directorySize($recipeDir.'/'.Guid::dash($id));
        }

        $total = 500 * 1024 * 1024;   // ALLOWED_SIZE

        return new JsonResponse([
            'usedStorageBytes' => $used,
            'usedStorageStr' => $this->prettySize($used),
            'totalStorageBytes' => $total,
            'totalStorageStr' => $this->prettySize($total),
        ]);
    }

    /** controller_group_households.py:14 */
    public function households(Request $request): JsonResponse
    {
        $builder = DB::connection('mealie')->table('households')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);

        return new JsonResponse(Pagination::paginate(
            $builder,
            Pagination::query($request),
            fn ($h) => GroupSerializer::householdSummary($h),
            '/groups/households'
        ));
    }

    /**
     * controller_group_households.py:21 — the parameter is a plain str resolved by
     * get_by_slug_or_id, so either a slug or a UUID works and a malformed value is a 404, not 422.
     */
    public function household(Request $request, string $slug): JsonResponse
    {
        $query = DB::connection('mealie')->table('households')
            ->where('group_id', $request->attributes->get('mealie_user')->group_id);

        $packed = Guid::pack($slug);
        $household = $packed !== null
            ? $query->where('id', $packed)->first()
            : $query->where('slug', $slug)->first();

        if (! $household) {
            return Errors::detail(404, 'Household not found');
        }

        return new JsonResponse(GroupSerializer::householdSummary($household));
    }

    private function group(Request $request): object
    {
        return DB::connection('mealie')->table('groups')
            ->where('id', $request->attributes->get('mealie_user')->group_id)->first();
    }

    /** checks.py:28-31 — a bare 403, no ErrorResponse body. */
    private function canManage(Request $request): ?JsonResponse
    {
        return (bool) $request->attributes->get('mealie_user')->can_manage ? null : Errors::forbidden();
    }

    /** fs_stats.py:25-40 — os.path.getsize(path) on the directory itself, then its contents. */
    private function directorySize(string $dir): int
    {
        if (! file_exists($dir)) {
            return 0;
        }

        $total = (int) @filesize($dir);

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            $total += is_dir($path) ? $this->directorySize($path) : (int) @filesize($path);
        }

        return $total;
    }

    /**
     * fs_stats.py:8-22 — round(x, 2) then Python's float repr, which always keeps at least one
     * decimal place: 500.0 MB, not 500 MB.
     */
    private function prettySize(int $size): string
    {
        if ($size < 1024) {
            return $size.' bytes';
        }

        foreach ([[1024 ** 2, 1024, 'KB'], [1024 ** 3, 1024 ** 2, 'MB'], [1024 ** 4, 1024 ** 3, 'GB']] as [$limit, $divisor, $unit]) {
            if ($size < $limit) {
                return self::pyFloat($size / $divisor).' '.$unit;
            }
        }

        return self::pyFloat($size / 1024 ** 4).' TB';
    }

    /** round(v, 2) rendered the way Python prints a float. */
    private static function pyFloat(float $value): string
    {
        $rounded = round($value, 2);
        $text = rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.');

        return str_contains($text, '.') ? $text : $text.'.0';
    }
}
