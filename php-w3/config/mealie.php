<?php

// Mirrors the Python settings this rewrite depends on.
// Sources are mealie/core/settings/settings.py and mealie/core/settings/directories.py.

$dataDir = env('MEALIE_DATA_DIR', dirname(base_path()).'/dev/data');

return [
    'data_dir' => $dataDir,
    'db_path' => env('MEALIE_DB_PATH', $dataDir.'/mealie.db'),

    // settings.py:72-74 — with PRODUCTION false, determine_secrets() returns a fixed
    // development constant instead of reading data/.secret. The oracle runs that way, and the
    // token minted by compx574/hurl/run.sh has to verify against it.
    'production' => env('MEALIE_PRODUCTION', false),
    'secret' => env('MEALIE_SECRET', 'shh-secret-test-key'),

    'token_time' => (int) env('MEALIE_TOKEN_TIME', 48),          // settings.py:160
    'is_demo' => env('MEALIE_IS_DEMO', false),                   // settings.py:153
    'allow_signup' => env('MEALIE_ALLOW_SIGNUP', false),         // settings.py:176
    'allow_password_login' => env('MEALIE_ALLOW_PASSWORD_LOGIN', true), // settings.py:177
    'default_group' => env('MEALIE_DEFAULT_GROUP', 'Home'),      // settings.py:280
    'default_household' => env('MEALIE_DEFAULT_HOUSEHOLD', 'Family'), // settings.py:281
    'default_email' => 'changeme@example.com',                   // settings.py:283

    // settings.py:50-55 plus ALLOWED_IFRAME_HOSTS, de-duplicated in order (settings.py:186-190)
    'default_allowed_iframe_hosts' => ['youtube.com', 'youtube-nocookie.com', 'vimeo.com', 'player.vimeo.com'],
    'allowed_iframe_hosts' => env('MEALIE_ALLOWED_IFRAME_HOSTS', ''),

    // settings.py:396-444. OIDC is never configured here; the guards in
    // mealie/routes/auth/auth.py are reproduced, the flows are not.
    'oidc_ready' => false,
    'oidc_redirect' => env('MEALIE_OIDC_AUTO_REDIRECT', false),  // settings.py:402
    'oidc_provider_name' => env('MEALIE_OIDC_PROVIDER_NAME', 'OAuth'), // settings.py:403

    'version' => env('MEALIE_VERSION', 'develop'),               // mealie/__init__.py:1

    'theme' => [  // mealie/schema/admin/about.py:29-43
        'light_primary' => env('THEME_LIGHT_PRIMARY', '#E58325'),
        'light_accent' => env('THEME_LIGHT_ACCENT', '#007A99'),
        'light_secondary' => env('THEME_LIGHT_SECONDARY', '#973542'),
        'light_success' => env('THEME_LIGHT_SUCCESS', '#43A047'),
        'light_info' => env('THEME_LIGHT_INFO', '#1976D2'),
        'light_warning' => env('THEME_LIGHT_WARNING', '#FF6D00'),
        'light_error' => env('THEME_LIGHT_ERROR', '#EF5350'),
        'dark_primary' => env('THEME_DARK_PRIMARY', '#E58325'),
        'dark_accent' => env('THEME_DARK_ACCENT', '#007A99'),
        'dark_secondary' => env('THEME_DARK_SECONDARY', '#973542'),
        'dark_success' => env('THEME_DARK_SUCCESS', '#43A047'),
        'dark_info' => env('THEME_DARK_INFO', '#1976D2'),
        'dark_warning' => env('THEME_DARK_WARNING', '#FF6D00'),
        'dark_error' => env('THEME_DARK_ERROR', '#EF5350'),
    ],
];
