<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * UserOut (mealie/schema/user/user.py:170), a MealieModel, so camelCase, in Pydantic field order:
 * UserBase's fields first, then UserOut's own.
 *
 * PrivateUser's extra fields (password, loginAttemps, lockedAt, tokensValidAfter) are deliberately
 * absent — they belong to user.py:208 and never reach this response.
 */
class UserSerializer
{
    /** users.auth_method holds the enum NAME; the response carries the enum VALUE. users.py:45-48. */
    private const AUTH_METHODS = ['MEALIE' => 'Mealie', 'LDAP' => 'LDAP', 'OIDC' => 'OIDC'];

    public static function out(object $user): array
    {
        $db = DB::connection('mealie');
        $group = $db->table('groups')->where('id', $user->group_id)->first();
        $household = $user->household_id ? $db->table('households')->where('id', $user->household_id)->first() : null;

        $tokens = $db->table('long_live_tokens')->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn ($t) => [
                'name' => $t->name,
                'id' => (int) $t->id,          // LongLiveTokenOut.id is an int, not a UUID
                'createdAt' => Dates::out($t->created_at),
            ])->all();

        return [
            'id' => Guid::dash($user->id),
            'username' => $user->username,
            'fullName' => $user->full_name,
            'email' => $user->email,
            'authMethod' => self::AUTH_METHODS[$user->auth_method] ?? $user->auth_method,
            'admin' => (bool) $user->admin,
            'group' => $group?->name,
            'household' => $household?->name,
            'advanced' => (bool) $user->advanced,
            'showAnnouncements' => (bool) $user->show_announcements,
            'lastReadAnnouncement' => $user->last_read_announcement,
            'canInvite' => (bool) $user->can_invite,
            'canManage' => (bool) $user->can_manage,
            'canManageHousehold' => (bool) $user->can_manage_household,
            'canOrganize' => (bool) $user->can_organize,
            'groupId' => Guid::dash($user->group_id),
            'groupSlug' => $group?->slug,
            'householdId' => Guid::dash($user->household_id),
            'householdSlug' => $household?->slug,
            'tokens' => $tokens,
            'cacheKey' => $user->cache_key,
        ];
    }

    /** UserRatingSummary (user.py:71) — three keys. Used by the /self/* rating routes. */
    public static function ratingSummary(object $row): array
    {
        return [
            'recipeId' => Guid::dash($row->recipe_id),
            'rating' => $row->rating === null ? null : (float) $row->rating,
            'isFavorite' => (bool) $row->is_favorite,   // user.py:78-83 turns null into false
        ];
    }

    /** UserRatingOut (user.py:95) — five keys. Used by the /{id}/* rating routes. */
    public static function ratingOut(object $row): array
    {
        return self::ratingSummary($row) + [
            'userId' => Guid::dash($row->user_id),
            'id' => Guid::dash($row->id),
        ];
    }
}
