<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * mealie/routes/users/_helpers.py:36-49, assert_user_change_allowed.
 *
 * Returns the 403 response to send, or null when the change is allowed.
 *
 * Four handlers (images.py:27, ratings.py:47/53/59) call this with the caller as *both* the current
 * user and the new data. Every comparison below is then between a value and itself, so only the
 * identity check can fire — which is why selfOnly() exists rather than reusing the full check with
 * a hand-built copy of the caller.
 */
class UserPermissions
{
    /** _helpers.py:7 */
    private const PERMISSION_ATTRS = ['can_invite', 'can_manage', 'can_manage_household', 'can_organize', 'admin'];

    /** The identity half, for the four callers that pass the current user as the new data. */
    public static function selfOnly(object $currentUser, string $targetId): ?JsonResponse
    {
        if (Guid::pack($currentUser->id) === Guid::pack($targetId)) {
            return null;
        }

        return self::forbidden((bool) $currentUser->admin
            ? 'Use the Admin API to update other users'       // _helpers.py:42
            : 'User cannot edit other users');                // _helpers.py:13
    }

    /** The full check, for PUT /users/{item_id} where new data really is new. */
    public static function changeAllowed(object $currentUser, string $targetId, array $newData): ?JsonResponse
    {
        if (! (bool) $currentUser->admin) {
            if (Guid::pack($currentUser->id) !== Guid::pack($targetId)) {
                return self::forbidden('User cannot edit other users');
            }

            if (self::permissionsChanged($currentUser, $newData)) {
                return self::forbidden('User cannot change their own permissions');
            }

            if (self::named('group', $currentUser, $newData)) {
                return self::forbidden("User doesn't have permission to change their group");
            }

            if (self::named('household', $currentUser, $newData)) {
                return self::forbidden("User doesn't have permission to change their household");
            }

            return null;
        }

        if (Guid::pack($currentUser->id) !== Guid::pack($targetId)) {
            return self::forbidden('Use the Admin API to update other users');
        }

        if (self::permissionsChanged($currentUser, $newData)) {
            return self::forbidden("Admins can't change their own permissions");
        }

        return null;
    }

    /** _helpers.py:15 — any of the five attributes differing is enough. */
    private static function permissionsChanged(object $currentUser, array $newData): bool
    {
        foreach (self::PERMISSION_ATTRS as $attr) {
            $camel = lcfirst(str_replace('_', '', ucwords($attr, '_')));
            $incoming = $newData[$camel] ?? $newData[$attr] ?? false;
            if ((bool) $currentUser->{$attr} !== (bool) $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * _helpers.py:22 and :28 compare the group and household *names*, which UserBase carries as
     * plain strings, not the ids.
     */
    private static function named(string $field, object $currentUser, array $newData): bool
    {
        $table = $field === 'group' ? 'groups' : 'households';
        $column = $field === 'group' ? 'group_id' : 'household_id';

        $current = \Illuminate\Support\Facades\DB::connection('mealie')
            ->table($table)->where('id', $currentUser->{$column})->value('name');

        return $current !== ($newData[$field] ?? null);
    }

    private static function forbidden(string $message): JsonResponse
    {
        return new JsonResponse(['detail' => ['message' => $message, 'error' => true, 'exception' => null]], 403);
    }
}
