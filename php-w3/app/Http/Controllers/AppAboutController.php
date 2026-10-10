<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** mealie/routes/app/app_about.py */
class AppAboutController extends Controller
{
    /** app_about.py:14 — AppInfo (mealie/schema/admin/about.py:13), a MealieModel, so camelCase. */
    public function about(): JsonResponse
    {
        return new JsonResponse([
            'production' => (bool) config('mealie.production'),
            'version' => (string) config('mealie.version'),
            'demoStatus' => (bool) config('mealie.is_demo'),
            'allowSignup' => (bool) config('mealie.allow_signup'),
            'allowPasswordLogin' => (bool) config('mealie.allow_password_login'),
            'defaultGroupSlug' => $this->defaultGroupSlug(),
            'defaultHouseholdSlug' => $this->defaultHouseholdSlug(),
            'enableOidc' => (bool) config('mealie.oidc_ready'),
            'oidcRedirect' => (bool) config('mealie.oidc_redirect'),
            'oidcProviderName' => (string) config('mealie.oidc_provider_name'),
            'tokenTime' => (int) config('mealie.token_time'),
            'allowedIframeHosts' => $this->allowedIframeHosts(),
        ]);
    }

    /** app_about.py:50 — AppStartupInfo (about.py:46). */
    public function startupInfo(): JsonResponse
    {
        $count = DB::connection('mealie')->table('users')
            ->where('email', config('mealie.default_email'))->count();

        return new JsonResponse([
            'isFirstLogin' => $count > 0,
            'isDemo' => (bool) config('mealie.is_demo'),
        ]);
    }

    /** app_about.py:66 — AppTheme (about.py:28), plus the handler's Cache-Control header. */
    public function theme(): JsonResponse
    {
        $out = [];
        foreach (config('mealie.theme') as $key => $value) {
            $out[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
        }

        return (new JsonResponse($out))->header('Cache-Control', 'public, max-age=604800');
    }

    /**
     * app_about.py:21-32 — the default group's slug, but only when the group exists and is not
     * private. Null otherwise.
     */
    private function defaultGroupSlug(): ?string
    {
        $group = DB::connection('mealie')->table('groups')
            ->where('name', config('mealie.default_group'))->first();

        if (! $group) {
            return null;
        }

        $prefs = DB::connection('mealie')->table('group_preferences')
            ->where('group_id', $group->id)->first();

        if (! $prefs || (bool) $prefs->private_group) {
            return null;
        }

        return $group->slug;
    }

    /**
     * app_about.py:28-32 — the default household inside that group, and only when the group slug
     * resolved. Three households share the name here, so the group_id filter is what picks one.
     */
    private function defaultHouseholdSlug(): ?string
    {
        if ($this->defaultGroupSlug() === null) {
            return null;
        }

        $group = DB::connection('mealie')->table('groups')
            ->where('name', config('mealie.default_group'))->first();

        $household = DB::connection('mealie')->table('households')
            ->where('name', config('mealie.default_household'))
            ->where('group_id', $group->id)->first();

        if (! $household) {
            return null;
        }

        $prefs = DB::connection('mealie')->table('household_preferences')
            ->where('household_id', $household->id)->first();

        if (! $prefs || (bool) $prefs->private_household) {
            return null;
        }

        return $household->slug;
    }

    /** settings.py:186-190 — defaults first, then the configured extras, de-duplicated in order. */
    private function allowedIframeHosts(): array
    {
        $extra = array_filter(array_map(
            fn ($h) => strtolower(trim($h)),
            explode(',', (string) config('mealie.allowed_iframe_hosts'))
        ));

        return array_values(array_unique(array_merge(config('mealie.default_allowed_iframe_hosts'), $extra)));
    }
}
