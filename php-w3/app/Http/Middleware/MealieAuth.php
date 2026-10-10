<?php

namespace App\Http\Middleware;

use App\Support\CurrentUser;
use App\Support\Errors;
use Closure;
use Illuminate\Http\Request;

/**
 * mealie:user  — mealie/routes/_base/routers.py:20-24, UserAPIRouter's Depends(get_current_user)
 * mealie:admin — dependencies.py:153-156, get_admin_user: 401 if unauthenticated, 403 if not admin
 * mealie:optional — try_get_current_user (dependencies.py:92-99); never rejects
 */
class MealieAuth
{
    public function handle(Request $request, Closure $next, string $mode = 'user')
    {
        $user = CurrentUser::resolve($request);
        $request->attributes->set('mealie_user', $user);

        if ($mode === 'optional') {
            return $next($request);
        }

        if ($user === null) {
            return Errors::credentials();
        }

        if ($mode === 'admin' && ! (bool) $user->admin) {
            return Errors::forbidden();
        }

        return $next($request);
    }
}
