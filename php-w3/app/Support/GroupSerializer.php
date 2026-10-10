<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Shapes for mealie/routes/groups/, read from the schemas and checked against the oracle. */
class GroupSerializer
{
    /** ReadGroupPreferences */
    public static function groupPreferences(?object $p): ?array
    {
        return $p === null ? null : [
            'privateGroup' => (bool) $p->private_group,
            'showAnnouncements' => (bool) $p->show_announcements,
            'groupId' => Guid::dash($p->group_id),
            'id' => Guid::dash($p->id),
        ];
    }

    /** HouseholdSummary.preferences — carries its own id, like the group one. */
    public static function householdPreferences(?object $p): ?array
    {
        return $p === null ? null : [
            'privateHousehold' => (bool) $p->private_household,
            'showAnnouncements' => (bool) $p->show_announcements,
            'lockRecipeEditsFromOtherHouseholds' => (bool) $p->lock_recipe_edits_from_other_households,
            'firstDayOfWeek' => (int) $p->first_day_of_week,
            'recipePublic' => (bool) $p->recipe_public,
            'recipeShowNutrition' => (bool) $p->recipe_show_nutrition,
            'recipeShowAssets' => (bool) $p->recipe_show_assets,
            'recipeLandscapeView' => (bool) $p->recipe_landscape_view,
            'recipeDisableComments' => (bool) $p->recipe_disable_comments,
            'id' => Guid::dash($p->id),
        ];
    }

    /** HouseholdSummary */
    public static function householdSummary(object $h): array
    {
        $prefs = DB::connection('mealie')->table('household_preferences')
            ->where('household_id', $h->id)->first();

        return [
            'groupId' => Guid::dash($h->group_id),
            'name' => $h->name,
            'id' => Guid::dash($h->id),
            'slug' => $h->slug,
            'preferences' => self::householdPreferences($prefs),
        ];
    }

    /** UserSummary — five keys, no email and no permission bits. */
    public static function userSummary(object $u): array
    {
        return [
            'id' => Guid::dash($u->id),
            'groupId' => Guid::dash($u->group_id),
            'householdId' => Guid::dash($u->household_id),
            'username' => $u->username,
            'fullName' => $u->full_name,
        ];
    }

    /** MultiPurposeLabelSummary / MultiPurposeLabelOut — same four keys. */
    public static function label(object $l): array
    {
        return [
            'name' => $l->name,
            'color' => $l->color,
            'groupId' => Guid::dash($l->group_id),
            'id' => Guid::dash($l->id),
        ];
    }

    /**
     * AIProviderSettingsOut (mealie/schema/group/ai_providers.py:107).
     *
     * Four declared fields then three @computed_field properties (:124-137), in that order:
     * aiEnabled is "a default provider is set", and the other two additionally require their own
     * provider id. The provider list carries only id and name — api_key is Field(exclude=True)
     * (:14) and never leaves the server.
     *
     * Providers hang off ai_providers.settings_id, not off group_id.
     */
    public static function aiProviderSettings(string $groupId): ?array
    {
        $db = DB::connection('mealie');
        $s = $db->table('ai_provider_settings')->where('group_id', $groupId)->first();
        if (! $s) {
            return null;
        }

        $rows = $db->table('ai_providers')->where('settings_id', $s->id)->orderBy('created_at')->get();
        $providers = $rows->map(fn ($p) => ['id' => Guid::dash($p->id), 'name' => $p->name])->all();
        $known = $rows->pluck('id')->all();

        // :112-122 — an id that does not name one of the listed providers is reported as null.
        $resolve = fn (?string $id) => $id !== null && in_array($id, $known, true) ? Guid::dash($id) : null;

        $default = $resolve($s->default_provider_id);
        $audio = $resolve($s->audio_provider_id);
        $image = $resolve($s->image_provider_id);

        $aiEnabled = $default !== null;

        return [
            'defaultProviderId' => $default,
            'audioProviderId' => $audio,
            'imageProviderId' => $image,
            'providers' => $providers,
            'aiEnabled' => $aiEnabled,
            'audioProviderEnabled' => $aiEnabled && $audio !== null,
            'imageProviderEnabled' => $aiEnabled && $image !== null,
        ];
    }

    /** GroupSummary — note this embeds the AI settings with no permission check. */
    public static function groupSummary(object $g): array
    {
        $prefs = DB::connection('mealie')->table('group_preferences')->where('group_id', $g->id)->first();

        return [
            'name' => $g->name,
            'id' => Guid::dash($g->id),
            'slug' => $g->slug,
            'preferences' => self::groupPreferences($prefs),
            'aiProviderSettings' => self::aiProviderSettings($g->id),
        ];
    }
}
