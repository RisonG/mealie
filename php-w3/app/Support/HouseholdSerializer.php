<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Shapes for mealie/routes/households/.
 *
 * cookbooks, webhook_urls, group_events_notifiers and recipe_actions are all empty in the shared
 * database, so the item shapes below are read from the schemas rather than confirmed against a live
 * response. The envelopes, statuses and permission paths are verified; individual item keys are
 * not, and the log records that.
 */
class HouseholdSerializer
{
    /** HouseholdInDB — verified: groupId, name, id, slug, preferences, group, users, webhooks. */
    public static function householdInDb(object $h): array
    {
        $db = DB::connection('mealie');
        $prefs = $db->table('household_preferences')->where('household_id', $h->id)->first();
        $group = $db->table('groups')->where('id', $h->group_id)->first();

        // users items carry two keys only, not the five of round 3's UserSummary.
        $users = $db->table('users')->where('household_id', $h->id)->orderBy('created_at')
            ->get()->map(fn ($u) => ['id' => Guid::dash($u->id), 'fullName' => $u->full_name])->all();

        $webhooks = $db->table('webhook_urls')->where('household_id', $h->id)->orderBy('created_at')
            ->get()->map(fn ($w) => self::webhook($w))->all();

        return [
            'groupId' => Guid::dash($h->group_id),
            'name' => $h->name,
            'id' => Guid::dash($h->id),
            'slug' => $h->slug,
            'preferences' => GroupSerializer::householdPreferences($prefs),
            'group' => $group?->name,
            'users' => $users,
            'webhooks' => $webhooks,
        ];
    }

    /** ReadWebhook (household/webhook.py:56) — CreateWebhook, then SaveWebhook's ids, then id. */
    public static function webhook(object $w): array
    {
        return [
            'enabled' => (bool) $w->enabled,
            'name' => $w->name,
            'url' => $w->url,
            'webhookType' => $w->webhook_type,
            'scheduledTime' => $w->time,
            'groupId' => Guid::dash($w->group_id),
            'householdId' => Guid::dash($w->household_id),
            'id' => Guid::dash($w->id),
        ];
    }

    /** ReadCookBook (cookbook/cookbook.py:72) — the full inheritance chain, in order. */
    public static function cookbook(object $c): array
    {
        $household = DB::connection('mealie')->table('households')->where('id', $c->household_id)->first();

        return [
            'name' => $c->name,
            'description' => $c->description ?? '',
            'slug' => $c->slug,
            'position' => (int) $c->position,
            'public' => (bool) $c->public,
            'queryFilterString' => $c->query_filter_string ?? '',
            'groupId' => Guid::dash($c->group_id),
            'householdId' => Guid::dash($c->household_id),
            'id' => Guid::dash($c->id),
            'queryFilter' => null,
            'household' => $household === null ? null : ['id' => Guid::dash($household->id), 'name' => $household->name, 'slug' => $household->slug],
        ];
    }

    /**
     * GroupEventNotifierOut (household/group_events.py:87).
     *
     * apprise_url is absent on purpose: it holds credentials and only the private schema carries
     * it. A null appriseUrl in an update means "keep the stored one" for the same reason.
     */
    public static function notifier(object $n): array
    {
        $options = DB::connection('mealie')->table('group_events_notifier_options')
            ->where('notifier_id', $n->id)->first();

        return [
            'id' => Guid::dash($n->id),
            'name' => $n->name,
            'enabled' => (bool) $n->enabled,
            'groupId' => Guid::dash($n->group_id),
            'householdId' => Guid::dash($n->household_id),
            'options' => self::notifierOptions($options),
        ];
    }

    private static function notifierOptions(?object $o): array
    {
        if ($o === null) {
            return [];
        }

        $out = [];
        foreach ((array) $o as $column => $value) {
            if (in_array($column, ['id', 'notifier_id', 'created_at', 'update_at'], true)) {
                continue;
            }
            $out[lcfirst(str_replace('_', '', ucwords($column, '_')))] = (bool) $value;
        }

        return $out;
    }

    /** GroupRecipeActionOut (household/group_recipe_action.py:39) */
    public static function recipeAction(object $a): array
    {
        return [
            'actionType' => $a->action_type,
            'title' => $a->title,
            'url' => $a->url,
            'groupId' => Guid::dash($a->group_id),
            'householdId' => Guid::dash($a->household_id),
            'id' => Guid::dash($a->id),
        ];
    }

    /** ReadInviteToken (household/invite_token.py:21) — note there is no id field. */
    public static function inviteToken(object $t): array
    {
        return [
            'token' => $t->token,
            'usesLeft' => (int) $t->uses_left,
            'groupId' => Guid::dash($t->group_id),
            'householdId' => Guid::dash($t->household_id),
        ];
    }
}
