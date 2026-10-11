# Workflow 3 — PHP rewrite contracts

One section per round. Each section is written from `mealie/` Python source only and is reviewed
before any PHP is written for it. `compx574/hurl/` is held out from the implementation rounds
(`compx574/workflow-3/log.md`, D2), so nothing below is derived from what `parity.hurl` asserts.

Conventions used throughout:

- **Path** — the full path as mounted. `mealie/routes/__init__.py:20` puts every package router under
  `/api`; each package adds its own prefix. `mealie/routes/utility_routes.py` is mounted separately at
  `mealie/app.py:150` and carries `/api/utils` in its own prefix.
- **IDs** — `mealie/db/models/_model_utils/guid.py`. On SQLite every id column is `CHAR(32)` holding
  **undashed** lowercase hex (`process_bind_param` → `f"{value.int:032x}"`). Python converts to
  `uuid.UUID` on read (`process_result_value`), so every id in a JSON response is **dashed**
  (`8-4-4-4-12`). PHP must dash on the way out and strip dashes on the way in to the database. Path and
  query parameters typed `UUID4` are validated as v4 before the handler runs; a malformed one is a 422,
  not a 404.
- **JSON key case** — `MealieModel` (`mealie/schema/_mealie/mealie_model.py:53`) sets
  `alias_generator=camelize`, so schemas inheriting it serialize **camelCase**. Schemas inheriting
  plain `pydantic.BaseModel` serialize their field names **unchanged**. Which base a schema uses is
  stated per endpoint below, because the two cases are mixed inside this round.
- **Request validation always precedes the handler, and therefore every permission check.**
  FastAPI parses and validates path parameters and the request body before calling the function;
  `self.checks.can_manage()`, `assert_user_change_allowed` and every other authorization test live
  *inside* it. So a non-v4 UUID in the path, or a body that is not an object of the right shape, is
  a **422 even for a caller who would have been refused with a 403** — and `parity.hurl` leans on
  exactly this, sending an all-zero UUID (not a valid v4) and a scalar body of `0`. Writing the
  permission check first is the natural mistake and gives the wrong status. Round 3 was scored
  twice because of it: 69/265 with the checks in the wrong order, 71/265 with them right.
- **Error bodies, measured against the oracle** — unknown route `404 {"detail":"Not Found"}`;
  wrong method `405 {"detail":"Method Not Allowed"}`; failed `get_current_user`
  `401 {"detail":"Could not validate credentials"}` with header `www-authenticate: Bearer`;
  unhandled exception `500` with Starlette's plain-text `Internal Server Error`.
- **422 body** — `mealie/routes/handlers.py:18-29` registers the debug validation handler only when
  `PRODUCTION` is false (or `TESTING`). The oracle runs with `PRODUCTION=False`, so a request-validation
  failure returns `{"status_code": 422, "message": "<str(exc)>", "data": null}`. The `message` text is
  FastAPI's repr of the error list; byte-equality is not attempted, only the status and the three keys.

---

## Round 1 — harness contract, `app`, `auth`, `validators`, `utility_routes`

16 endpoints. Round 1 also owns the Laravel skeleton, because `compx574/hurl/run.sh` cannot mint a
token without it.

### 1.0 Harness contract (not an API endpoint)

Read from `compx574/hurl/run.sh`. These names are fixed by the shared scorer and may not be renamed:

| Requirement | Value |
|---|---|
| Candidate directory | `php-w3/` at the repository root |
| Bootstrap | `php-w3/vendor/autoload.php`, then `php-w3/bootstrap/app.php` returning an app whose `Illuminate\Contracts\Console\Kernel` can `bootstrap()` |
| DB connection name | `mealie` — `DB::connection("mealie")` must reach `dev/data/mealie.db` |
| Token class | `App\Auth\Jwt` with **static** `encode(array $payload, string $secret): string` and **static** `secret(): string` |
| Signing | HS256 (`mealie/core/security/tokens.py:7`), `iss` claim `mealie` (`tokens.py:8`) |
| Secret | Must equal the Python oracle's `settings.SECRET`. With `PRODUCTION=False`, `determine_secrets` (`mealie/core/settings/settings.py:72-74`) returns a hard-coded development constant rather than reading `.secret`; `Jwt::secret()` must return that same constant. Not reproduced here |
| Payload minted by run.sh | `sub` (dashed user UUID), `rme`, `iss`, `iat`, `exp` |
| Listen address | `http://127.0.0.1:9001` |
| Forbidden | `php artisan migrate` against `dev/data/mealie.db`; any write to that file outside a handler's normal behaviour |

The skeleton also owns, because every later round depends on them and no round may redefine them:
the `mealie` connection, `App\Auth\Jwt`, bearer/cookie auth middleware, dashed/undashed id helpers,
the date formatter, the FastAPI-shaped 404/405/422/500 bodies, and the pagination envelope.

**Not implemented in the skeleton:** nothing that answers a route. The floor score is measured on it
before any route code is written.

---

### 1.1 `GET /api/app/about`

- **Python source** — `mealie/routes/app/app_about.py:14` (`get_app_info`). Mounted
  `/api` (`routes/__init__.py:20`) + `/app` (`routes/app/__init__.py:5`) + `/about`
  (`app_about.py:11`) + `""`.
- **Schema** — `mealie/schema/admin/about.py:13` `AppInfo`, a `MealieModel` → **camelCase** keys:
  `production`, `version`, `demoStatus`, `allowSignup`, `allowPasswordLogin`, `defaultGroupSlug`,
  `defaultHouseholdSlug`, `enableOidc`, `oidcRedirect`, `oidcProviderName`, `tokenTime`,
  `allowedIframeHosts`.
- **Values** — all from settings except the two slugs. `version` is `mealie/__init__.py:1`
  (`"develop"` on this branch). `allowedIframeHosts` is `settings.py:186-190`: the four defaults at
  `settings.py:50-55` plus any comma-separated additions, de-duplicated in order.
  `defaultGroupSlug` is the slug of the group named `settings.DEFAULT_GROUP` (`"Home"`,
  `settings.py:280`) **only if** that group exists and `preferences.private_group` is false, else
  `null`. `defaultHouseholdSlug` is the household named `settings.DEFAULT_HOUSEHOLD` (`"Family"`,
  `settings.py:281`) **within that group**, only if the group slug resolved and
  `preferences.private_household` is false, else `null`.
- **IDs** — none in the response.
- **Who may call it** — anyone. Plain `APIRouter`, no `Depends` on any user dependency.
- **Not implemented** — nothing; the two slug lookups are real queries against `groups`,
  `group_preferences`, `households` and `household_preferences`, not constants.

### 1.2 `GET /api/app/about/startup-info`

- **Python source** — `app_about.py:50` (`get_startup_info`).
- **Schema** — `mealie/schema/admin/about.py:46` `AppStartupInfo`, a `MealieModel` → camelCase:
  `isFirstLogin`, `isDemo`.
- **Value** — `isFirstLogin` is true when at least one row in `users` has
  `email = settings._DEFAULT_EMAIL` (`"changeme@example.com"`, `settings.py:283`). `isDemo` is
  `settings.IS_DEMO` (default false, `settings.py:153`).
- **IDs** — none.
- **Who may call it** — anyone, no auth dependency.
- **Not implemented** — nothing.

### 1.3 `GET /api/app/about/theme`

- **Python source** — `app_about.py:66` (`get_app_theme`).
- **Schema** — `mealie/schema/admin/about.py:28` `AppTheme`, a `MealieModel` → camelCase:
  `lightPrimary`, `lightAccent`, `lightSecondary`, `lightSuccess`, `lightInfo`, `lightWarning`,
  `lightError`, and the seven `dark*` equivalents. Defaults are the hex strings at
  `about.py:29-43`, overridable by `THEME_*` settings.
- **Header** — the handler sets `Cache-Control: public, max-age=604800` (`app_about.py:71`).
- **IDs** — none.
- **Who may call it** — anyone, no auth dependency.
- **Not implemented** — nothing.

---

### 1.4 `POST /api/auth/token`

- **Python source** — `mealie/routes/auth/auth.py:134` (`get_token`), on `public_router`
  (`auth.py:26`). Mounted `/api` + `/auth` (`routes/auth/__init__.py:5`).
- **Request** — `multipart/form-data` or `application/x-www-form-urlencoded`, fields `username`,
  `password`, `remember_me`, each defaulting to empty/false (`mealie/schema/user/auth.py:46-57`,
  `CredentialsRequestForm`). Field names are snake_case; this is a form class, not a `MealieModel`.
- **Response** — `MealieAuthToken` (`auth.py:119`), a plain `pydantic.BaseModel` → keys stay
  **snake_case**: `access_token`, `token_type` (`"bearer"`), `expires_in` (seconds, integer).
- **Statuses** — 423 with `{"detail": "User is locked out"}` when the provider raises `UserLockedOut`;
  401 with no detail when authentication fails; 200 otherwise.
- **Side effect** — sets cookie `mealie.access_token` (`auth.py:59`) with the attributes from
  `session_cookie_attrs` (`auth.py:82-97`): `path=/`, `secure` only when the request is HTTPS or
  `x-forwarded-proto`'s first entry is https, `samesite=lax` unless the request is both secure and
  carries `x-mealie-embedded: true` (then `none` + partitioned), `httponly=false`, and `max-age` set
  only when `remember_me` is true.
- **IDs** — the signed `sub` claim is the **dashed** user UUID (`auth.py:327` uses
  `str(current_user.id)` on the refresh path; the login path goes through the auth provider).
- **Who may call it** — anyone.
- **Not implemented** — LDAP. `get_auth_provider` (`mealie/core/security/security.py`) selects LDAP
  when configured; PHP implements the credentials provider only. Account lock-out counting and
  `tokens_valid_after` invalidation **are** implemented, because `get_current_user` depends on the
  latter.

### 1.5 `GET /api/auth/oauth`

- **Python source** — `auth.py:167` (`oauth_login`), `public_router`.
- **Behaviour on this deployment** — `oauth` is `None` unless `settings.OIDC_READY` was true at import
  time (`auth.py:32-33`), so the handler raises **500** with
  `{"detail": "Could not initialize OAuth client"}` (`auth.py:169-173`).
- **IDs** — none.
- **Who may call it** — anyone.
- **Not implemented** — the OIDC authorization redirect itself. PHP reproduces the
  not-configured branch only: 500 with that exact detail. This is a deliberate partial
  implementation, recorded here rather than left as a silent stub — if OIDC were configured the PHP
  response would be wrong, and the report must say so.

### 1.6 `GET /api/auth/oauth/callback`

- **Python source** — `auth.py:191` (`oauth_callback`), `public_router`.
- **Behaviour on this deployment** — same `oauth is None` guard, **500** with
  `{"detail": "Could not initialize OAuth client"}` (`auth.py:193-197`).
- **Who may call it** — anyone.
- **Not implemented** — the authorization-code exchange and `OpenIDProvider` user provisioning. Same
  caveat as 1.5.

### 1.7 `GET /api/auth/oauth/native/config`

- **Python source** — `auth.py:226` (`oauth_native_config`), `public_router`.
- **Schema** — `mealie/schema/user/auth.py:29` `OIDCNativeConfig`, a plain `BaseModel` →
  **snake_case**: `authorization_endpoint`, `client_id`, `scope`.
- **Behaviour on this deployment** — `not settings.OIDC_READY or not oauth` → **404** with
  `{"detail": "OIDC is not configured"}` (`auth.py:229-233`). Note this guard returns 404 where 1.5
  and 1.6 return 500; the difference is in the Python and must be reproduced.
- **Who may call it** — anyone.
- **Not implemented** — the server-metadata fetch. Same caveat as 1.5.

### 1.8 `POST /api/auth/oauth/native/token`

- **Python source** — `auth.py:244` (`oauth_native_token`), `public_router`.
- **Request** — JSON body `NativeOIDCTokenRequest` (`mealie/schema/user/auth.py:37`), plain
  `BaseModel` → snake_case: `code`, `code_verifier`, `redirect_uri`, `nonce` (nullable).
- **Order of checks** — FastAPI validates the body **before** the handler runs, so a body that is not
  an object with `code`, `code_verifier` and `redirect_uri` is a **422** with the debug body, not the
  404 below. PHP must validate the body first for the same reason.
- **Behaviour on this deployment** — with a valid body, `not settings.OIDC_READY or not oauth` →
  **404** `{"detail": "OIDC is not configured"}` (`auth.py:257-261`).
- **Who may call it** — anyone.
- **Not implemented** — the PKCE code exchange. Same caveat as 1.5.

### 1.9 `POST /api/auth/refresh`

- **Python source** — `auth.py:301` (`refresh_token`), on `user_router`
  (`auth.py:27`), which is a `UserAPIRouter` (`mealie/routes/_base/routers.py:20-24`) carrying
  `Depends(get_current_user)` for every route on it.
- **Who may call it** — any authenticated user. `get_current_user`
  (`mealie/core/dependencies/dependencies.py:102`) decodes the bearer token with HS256 and
  `settings.SECRET`; on `PyJWTError`, missing `sub`, or unknown user it raises **401** with
  `{"detail": "Could not validate credentials"}` and header `WWW-Authenticate: Bearer`
  (`dependencies.py:28-32`). The token is taken from the `Authorization` header or, if absent, the
  `mealie.access_token` cookie (`get_auth_token`, `dependencies.py:76-89`). A token whose `iat` is
  older than the user's `tokens_valid_after` is rejected as 401 (`dependencies.py:133-136`); a token
  with **no** `iat` is also rejected when `tokens_valid_after` is set.
- **Handler** — decodes the token a second time; a long-lived API token (`long_token` claim present)
  is refused with **400** `{"detail": "API tokens cannot be exchanged for a session token"}`
  (`auth.py:318-324`). Otherwise mints a new token carrying `sub` and the old `rme`.
- **Response** — `MealieAuthToken`, snake_case `access_token` / `token_type` / `expires_in`, and the
  session cookie is re-set.
- **IDs** — `sub` is the dashed user UUID (`auth.py:327`, `str(current_user.id)`).
- **Not implemented** — nothing.

### 1.10 `POST /api/auth/logout`

- **Python source** — `auth.py:332` (`logout`), on `user_router` → `Depends(get_current_user)`, so an
  unauthenticated caller gets 401 before the handler runs.
- **Response** — `{"message": "<translated>"}` from
  `get_locale_provider(accept_language).t("notifications.logged-out")`. The key is
  `notifications.logged-out` in `mealie/lang/messages/`; the `Accept-Language` header selects the
  file.
- **Side effect** — clears `mealie.access_token` by re-setting it empty with `max-age=0` and the
  **same** attributes from `session_cookie_attrs` (`auth.py:340`).
- **IDs** — none.
- **Not implemented** — only `en-US` is loaded. Any other `Accept-Language` falls back to the English
  string instead of the translated one.

---

### 1.11–1.15 Validators

All five are on a plain `APIRouter` (`mealie/routes/validators/validators.py:11`) mounted at
`/api` + `/validators` (`routes/validators/__init__.py:9`). **No auth dependency on any of them** —
they are callable anonymously, which is why `include_in_schema=False` hides them from the OpenAPI
document but does not protect them.

All five return `ValidationResponse` (`mealie/schema/response/validation.py:4`), a plain
`pydantic.BaseModel` with one field — so the body is exactly `{"valid": true}` or `{"valid": false}`,
**not** camelCased and **not** wrapped. `valid` is true when nothing matching was found.

All five take their input as **required query parameters**; a missing one is a 422 with the debug body.

| # | Path | Query | Python | Lookup |
|---|---|---|---|---|
| 1.11 | `GET /api/validators/user/name` | `name` (str) | `validators.py:14` | `users.get_one(name, "username", any_case=True)` — case-insensitive match on `users.username` |
| 1.12 | `GET /api/validators/user/email` | `email` (str) | `validators.py:22` | `users.get_one(email, "email", any_case=True)` — case-insensitive match on `users.email` |
| 1.13 | `GET /api/validators/group` | `name` (str) | `validators.py:30` | `groups.get_by_name(name)` → `mealie/repos/repository_group.py:53`, exact `filter_by(name=name)`, `one_or_none()` |
| 1.14 | `GET /api/validators/household` | `name` (str) | `validators.py:38` | see below |
| 1.15 | `GET /api/validators/recipe` | `group_id` (**UUID4**), `name` (str) | `validators.py:46` | `recipes.get_by_slug(group_id, slugify(name))` |

**1.14 always returns 500.** The route builds its repositories with `group_id=None`
(`validators.py:41`), and `HouseholdRepository.get_by_name`
(`mealie/repos/repository_household.py:69-71`) opens with `if not self.group_id: raise
Exception("group_id not set")`. The exception is unhandled, so every call to
`/api/validators/household` — any name, existing or not — is a **500**, never a
`ValidationResponse`. Because the exception is unhandled, Starlette returns its own plain-text body
`Internal Server Error` with `content-type: text/plain; charset=utf-8`, **not** a JSON `detail`
object (verified against the oracle). This is an upstream defect in Mealie, not a scoring artefact. PHP reproduces the
500 and this contract records it so that the matching 500 is not mistaken for a PHP bug.

**1.15 `group_id` is typed `UUID4`**, so FastAPI rejects a missing, malformed or non-v4 value with a
**422** before the handler runs. The slug is `python-slugify`'s `slugify(name)`; the PHP
implementation must match its behaviour (lowercase, non-alphanumerics collapsed to single hyphens,
leading/trailing hyphens stripped) because the lookup is on `recipes.slug` scoped to
`recipes.group_id`, compared against the **undashed** stored form of `group_id`.

**IDs** — `group_id` arrives dashed in the query string and is compared against the undashed
`CHAR(32)` column. No id appears in any validator response.

**Not implemented** — nothing. All five are implemented, 1.14 as its 500.

---

### 1.16 `GET /api/utils/download`

- **Python source** — `mealie/routes/utility_routes.py:12` (`download_file`). This module is **not**
  under `mealie/routes/__init__.py`; it declares its own full prefix `/api/utils`
  (`utility_routes.py:9`) and is included directly at `mealie/app.py:150`. The group's module list
  does not name it (log D5).
- **Query** — `token`, optional (`validate_file_token`, `dependencies.py:178`).
- **Order of checks**, all from `dependencies.py:178-205` then the handler:
  1. no `token` → **400** `{"detail": "Bad Request"}` (FastAPI fills an omitted detail with the
     status phrase; verified against the oracle)
  2. token fails HS256 decode, or has no usable `file` claim → **401**
     `{"detail": "could not validate file token"}`
  3. the path in the claim does not exist → **400**
  4. the resolved path is not inside `dirs.BACKUP_DIR` or `dirs.GROUPS_DIR` → **400**
     (`utility_routes.py:25-26`)
  5. the resolved path is not a regular file → **400** (`utility_routes.py:28-29`)
- **Success** — the file as `application/octet-stream` with
  `Content-Disposition: attachment; filename="<basename>"`.
- **Who may call it** — anyone holding a file token. There is **no** `get_current_user` on this route;
  the token itself is the authorization, and it is signed with the same secret as session tokens,
  which is why step 2 checks for the `file` claim specifically rather than trusting a valid signature.
- **IDs** — none.
- **Not implemented** — nothing. Note the allow-list is checked **after** existence, so a path that is
  outside the allowed directories and also missing returns 400 from step 3, not step 4 — the status is
  the same either way.

---

### Out of scope for round 1

- `mealie/routes/spa/` — excluded by the group's prompt for every round.
- OIDC beyond the not-configured branches of 1.5–1.8, and LDAP in 1.4, as stated per endpoint.
- `mealie/routes/auth/auth_cache.py` — only used to back the authlib OAuth client, which is never
  constructed here.

---

## Round 2 — `users`

17 endpoints from `mealie/routes/users/`. Mount points are set in
`mealie/routes/users/__init__.py:6-15` and are not uniform: `api_tokens.py` and `crud.py` carry
`/users` in their own router, while `registration.py`, `forgot_password.py`, `images.py` and
`ratings.py` are included **with** `prefix="/users"`. Working the prefix out per file is the only way
to get these paths right.

Two facts that apply to the whole module:

- **`auth_method` is stored as the enum name and serialized as the enum value.**
  `mealie/db/models/users/users.py:45-48` defines `MEALIE = "Mealie"`. The `users.auth_method`
  column holds `MEALIE`; `/api/users/self` returns `"authMethod": "Mealie"`. PHP must map
  `MEALIE → Mealie`, `LDAP → LDAP`, `OIDC → OIDC` on the way out.
- **`UserRatings` is a plain `BaseModel`** (`mealie/schema/user/user.py:105`), so the envelope is
  `{"ratings": [...]}`; the items inside are `MealieModel`s and are camelCased.

### 2.1 `POST /api/users/register`

- **Python** — `mealie/routes/users/registration.py:20`, on a plain `APIRouter(prefix="/register")`
  included with `prefix="/users"`. `BasePublicController`, so **anonymous**.
- **Body** — `CreateUserRegistration` (`mealie/schema/user/registration.py:10`), a `MealieModel`, so
  the client sends camelCase: `group`, `household`, `groupToken`, `email`, `username`, `fullName`,
  `password`, `passwordConfirm`, `advanced`, `private`, `seedData`, `locale`. `email` and `username`
  are lower-cased and stripped; `fullName` is stripped. Two validators run before the handler:
  `password_confirm` must equal `password`, and `locale` must be a known locale — both are **422**.
- **Validator precedence, measured against the oracle.** A third validator runs before the handler:
  `group_or_token` (`registration.py:38-44`) rejects the body with **422** unless `group` or
  `group_token` is truthy. So a registration with neither — the common case — never reaches the
  handler's 403; it is a 422 whose `loc` is `('body', 'group_token')` for an absent token and
  `('body', 'groupToken')` for an empty-string one. The 403 below is reachable only when `group` is
  non-empty.
- **Guard** — `registration.py:24-28`: when `ALLOW_SIGNUP` is false and `group_token` is null, or
  `group_token` is the empty string, **403** with
  `{"detail": {"message": "User Registration is Disabled", "error": true, "exception": null}}`.
  `ErrorResponse` (`mealie/schema/response/responses.py:8`) is a plain `BaseModel`, so those three
  keys keep their names. Note the Python precedence: `not ALLOW_SIGNUP and token is None` **or**
  `token == ""` — an empty-string token is refused even when signup is allowed.
- **Success** — **201** with `UserOut`.
- **IDs** — the new user's dashed UUID in the response.
- **Not implemented** — `RegistrationService.register_user` itself: group/household creation, seed
  data, and the `user_signup` event dispatch. On this deployment `ALLOW_SIGNUP` is false
  (`settings.py:176`), so every call without a group token is the 403 above and the service is
  never reached. A call **with** a valid group token would be wrong in PHP; recorded, not hidden.

### 2.2 `GET /api/users/self`

- **Python** — `mealie/routes/users/crud.py:19`, on `UserAPIRouter(prefix="/users")`, so
  `Depends(get_current_user)` → 401 as in round 1 §1.9.
- **Schema** — `UserOut` (`mealie/schema/user/user.py:170`), a `MealieModel` → camelCase, in field
  order: `id`, `username`, `fullName`, `email`, `authMethod`, `admin`, `group`, `household`,
  `advanced`, `showAnnouncements`, `lastReadAnnouncement`, `canInvite`, `canManage`,
  `canManageHousehold`, `canOrganize`, `groupId`, `groupSlug`, `householdId`, `householdSlug`,
  `tokens`, `cacheKey`. `password`, `loginAttemps`, `lockedAt` and `tokensValidAfter` belong to
  `PrivateUser` (`user.py:208`) and are **not** in this response.
- **Joins** — `group` and `household` are the related rows' `name`, `groupSlug`/`householdSlug`
  their `slug`. `tokens` is `LongLiveTokenOut` (`user.py:33`): `name`, `id` (an **integer**, not a
  UUID), `createdAt`.
- **IDs** — `id`, `groupId`, `householdId` are dashed; stored undashed.
- **Not implemented** — nothing.

### 2.3 `GET /api/users/self/ratings`

- **Python** — `crud.py:23`. Authenticated user only.
- **Schema** — `UserRatings[UserRatingSummary]` → `{"ratings": [...]}` where each item
  (`user.py:71`) is `{"recipeId": <dashed>, "rating": <float|null>, "isFavorite": <bool>}`.
  `is_favorite` has a before-validator turning null into `false` (`user.py:78-83`).
- **Source** — `users_to_recipes` rows for the caller's own id. No `favorites_only` filter.
- **Not implemented** — nothing.

### 2.4 `GET /api/users/self/ratings/{recipe_id}`

- **Python** — `crud.py:27`. `recipe_id` is typed `UUID4`, so a non-v4 value is a **422**.
- **Found** — `UserRatingSummary`, the same three camelCase keys as §2.3.
- **Not found** — **404** with
  `{"detail": {"message": "User has not rated this recipe", "error": true, "exception": null}}`
  (`crud.py:33-36`).
- **Not implemented** — nothing.

### 2.5 `GET /api/users/self/favorites`

- **Python** — `crud.py:38`. As §2.3 but `favorites_only=True`, i.e. `is_favorite` is true.
- **Not implemented** — nothing.

### 2.6 `PUT /api/users/password`

- **Python** — `crud.py:42`. Authenticated user; acts on the caller only.
- **Body** — `ChangePassword` (`user.py:61`), a `MealieModel` → `currentPassword` (defaults to `""`)
  and `newPassword`, which has `min_length=8`, so a shorter one is a **422** before the handler.
- **Order of checks** — LDAP account → **400** `user.ldap-update-password-unavailable`; wrong
  current password → **400** `user.invalid-current-password`; update failure → **400**
  `"Failed to update password"`. All three are `ErrorResponse` under `detail`.
- **Success** — `SuccessResponse` (`responses.py:22`), a plain `BaseModel`:
  `{"message": "<user.password-updated>", "error": false}`.
- **Translation keys** — `mealie/lang/messages/en-US.json`; only `en-US` is loaded (round 1 §1.10).
- **Side effect** — bcrypt-hashes the new password and writes `users.password`. **This writes to the
  shared database**, so the round's self-check must not call it with a correct current password.
- **Not implemented** — nothing.

### 2.7 `PUT /api/users/{item_id}`

- **Python** — `crud.py:65`. `item_id` is `UUID4` → non-v4 is a **422**.
- **Body** — `UserBase` (`user.py:109`), camelCase.
- **Permission** — `assert_user_change_allowed` (`mealie/routes/users/_helpers.py:36-49`), and the
  branches differ by role, which is the part worth getting right:
  - **non-admin** (`_helpers.py:10-33`): editing anyone else → **403** `"User cannot edit other
    users"`; changing any of `can_invite`, `can_manage`, `can_manage_household`, `can_organize`,
    `admin` → **403** `"User cannot change their own permissions"`; changing `group` → **403**
    `"User doesn't have permission to change their group"`; changing `household` → **403**
    `"User doesn't have permission to change their household"`.
  - **admin** (`_helpers.py:41-49`): editing anyone else → **403** `"Use the Admin API to update
    other users"`; changing their own permission attributes → **403** `"Admins can't change their
    own permissions"`.
  - The token `run.sh` mints belongs to `bob`, who is **not** an admin, so the non-admin branch is
    the one exercised.
- **Success** — `SuccessResponse` with `user.user-updated`.
- **Side effect** — writes the `users` row. Excluded from the self-check for the same reason as §2.6.
- **Not implemented** — nothing.

### 2.8 `POST /api/users/forgot-password`

- **Python** — `mealie/routes/users/forgot_password.py:13`, a plain `APIRouter(prefix="")` included
  with `prefix="/users"` — **anonymous**, no auth dependency.
- **Body** — `ForgotPassword` (`mealie/schema/user/user_passwords.py:11`): `{"email": str}`. A
  missing body is a **422**.
- **Behaviour** — `PasswordResetService.send_reset_email`. SMTP is not configured here. For an
  address with no matching user the oracle answers **200** with a body of literally `null`, and
  writes nothing (verified).
- **Not implemented** — sending mail, and creating the `password_reset_tokens` row. The PHP
  returns what Python returns for an unknown address without writing a row. The behaviour for a
  **known** address differs, and this is the one endpoint in the round where the PHP deliberately
  does less than the Python rather than matching it. Recorded here, not stubbed silently.

### 2.9 `POST /api/users/reset-password`

- **Python** — `forgot_password.py:24`. Anonymous.
- **Body** — `ResetPassword` (`user_passwords.py:23`), a `MealieModel`, fields `token`, `email`,
  `password`, `passwordConfirm`. Note `passwordConfirm` is spelled camelCase **in the Python
  source**, so camelizing it is a no-op — do not snake_case it.
- **Behaviour** — `PasswordResetService.reset_password` looks the token up in
  `password_reset_tokens`. An unknown token is **400** `{"detail": "Invalid token"}` — a bare
  string detail, like §2.12's 404 and unlike the `ErrorResponse` objects elsewhere (verified).
- **Not implemented** — the successful reset path, for the same reason as §2.8 and because it
  writes `users.password`. The rejection path is implemented.

### 2.10 `POST /api/users/{id}/image`

- **Python** — `mealie/routes/users/images.py:19`, `UserAPIRouter(prefix="")` included with
  `prefix="/users"`. Authenticated; `id` is `UUID4`.
- **Body** — `multipart/form-data`, file field `profile`, required → missing is a **422**.
- **Permission** — `assert_user_change_allowed(id, self.user, self.user)`. Passing the caller as
  *both* current and new data means the permission-attribute and group/household comparisons always
  compare equal, so the only check that can fire is the identity one: a non-admin editing another
  user is **403** `"User cannot edit other users"`, an admin editing another user is **403**
  `"Use the Admin API to update other users"`.
- **Behaviour** — writes `<data>/users/<id>/profile.webp` and patches `users.cache_key`.
- **Not implemented** — the image conversion and the file write. Pillow's WebP encoding is not
  reproduced; the PHP implements the permission and validation path and stops before writing. This
  also keeps the round from mutating the shared database via `cache_key`.

### 2.11 `POST /api/users/api-tokens`

- **Python** — `mealie/routes/users/api_tokens.py:21`, `UserAPIRouter(prefix="/users")` included
  **without** an extra prefix. Authenticated.
- **Body** — `LongLiveTokenIn` (`user.py:28`): `name`, `integrationId` (defaults to
  `DEFAULT_INTEGRATION_ID`).
- **Behaviour** — mints a token with claims `long_token: true`, `id` (the caller's **dashed** id),
  `name`, `integration_id`, and a **1825-day** lifetime (`api_tokens.py:35-36`), then inserts a
  `long_live_tokens` row.
- **Success** — **201** with `LongLiveTokenCreateResponse` (`user.py:44`): `name`, `id` (integer),
  `createdAt`, `token`.
- **Side effect** — inserts a row. Excluded from the self-check.
- **Not implemented** — nothing, but see the side-effect note.

### 2.12 `DELETE /api/users/api-tokens/{token_id}`

- **Python** — `api_tokens.py:49`. `token_id` is an **`int`**, not a UUID — `long_live_tokens.id` is
  an INTEGER primary key. A non-integer is a **422**.
- **Order of checks** — unknown id → **404** with the bare string detail
  `"Could not locate token with id '<id>' in database"` (not an `ErrorResponse` object); a token
  owned by someone else → **403** (`api_tokens.py:59-63`). Ownership is compared on `user_id`, not
  on email.
- **Success** — `DeleteTokenResponse` (`user.py:56`) → `{"tokenDelete": "<name>"}`.
- **Not implemented** — nothing. The 404 and 403 paths do not write; the success path does and is
  excluded from the self-check.

### 2.13 `GET /api/users/{id}/ratings`

- **Python** — `mealie/routes/users/ratings.py:44`, `UserAPIRouter()` **with no prefix of its own**,
  included with `prefix="/users"`. Authenticated; `id` is `UUID4`.
- **Permission** — `assert_user_change_allowed(id, self.user, self.user)`, so as in §2.10 only the
  identity check can fire: asking for another user's ratings is a **403**, with the message
  depending on whether the caller is an admin.
- **Schema** — `UserRatings[UserRatingOut]`. `UserRatingOut` (`user.py:95`) extends
  `UserRatingCreate`, so an item carries **five** keys, not three: `recipeId`, `rating`,
  `isFavorite`, `userId`, `id` — all UUIDs dashed. This differs from the `/self/ratings` summary in
  §2.3 and the difference is easy to miss.
- **Not implemented** — nothing.

### 2.14 `GET /api/users/{id}/favorites`

- **Python** — `ratings.py:50`. As §2.13 with `favorites_only=True`.
- **Not implemented** — nothing.

### 2.15 `POST /api/users/{id}/ratings/{slug}`

- **Python** — `ratings.py:56`. Authenticated; `id` is `UUID4`, `slug` is a free string.
- **Body** — `UserRatingUpdate` (`user.py:90`): `rating` (float, nullable), `isFavorite` (bool,
  nullable). Both optional, so `{}` is valid.
- **Order** — permission check first (§2.13), then `get_recipe_or_404` (`ratings.py:23-42`), which
  resolves `slug` **as a UUID first** and falls back to a slug lookup, scoped to the caller's group.
  Not found → **404** `{"detail": {"message": "Not found.", "error": true, "exception": null}}`.
- **Behaviour** — upsert into `users_to_recipes`: insert when the user has no row for that recipe,
  otherwise update only the fields that were sent (`ratings.py:73-78`).
- **Response** — the handler returns `None`, so the body is `null` with status **200**.
- **Side effect** — writes. Excluded from the self-check; the 403 and 404 paths are safe to call.
- **Not implemented** — nothing.

### 2.16 `POST /api/users/{id}/favorites/{slug}`

- **Python** — `ratings.py:80`. Calls §2.15 with `is_favorite=True` and no rating, so every status
  and body above applies unchanged.
- **Not implemented** — nothing.

### 2.17 `DELETE /api/users/{id}/favorites/{slug}`

- **Python** — `ratings.py:85`. Calls §2.15 with `is_favorite=False`. Note this **updates** the row
  rather than deleting it, and creates one if none existed.
- **Not implemented** — nothing.

### Out of scope for round 2

- Administering *other* users (`GET /api/users`, admin-side create/delete) lives in
  `mealie/routes/admin/` and belongs to round 9.
- Email delivery and the password-reset token lifecycle (§2.8, §2.9).
- Profile image encoding and storage (§2.10).
- `RegistrationService`, including group and household creation and seed data (§2.1).

### Database safety for this round

Seven endpoints write: §2.6, §2.7, §2.10, §2.11, §2.12 (success path), §2.15, §2.16, §2.17. The
round's self-check exercises their rejection paths only, and `dev/data/mealie.db` is compared
against `dev/data/mealie.w3-snapshot.db` after the run.

---

## Round 3 — `groups`

27 endpoints from `mealie/routes/groups/`. Unlike round 2, every router here carries its full prefix
(`/groups/...`) and `mealie/routes/groups/__init__.py:13` includes them without adding one, so the
paths are read straight off each `APIRouter(prefix=...)`.

Four facts that apply to the whole module:

- **Authentication comes from the controller class, not the router.** Four of these files use a
  plain `APIRouter`, but every controller subclasses `BaseUserController`
  (`mealie/routes/_base/base_controllers.py:132`), whose `user: PrivateUser = Depends(get_current_user)`
  applies to every route on the controller. So **all 27 require a user**; the router type is not the
  signal.
- **`self.repos` is scoped to the caller's group** (`base_controllers.py:155-157` →
  `AllRepositories(..., group_id=self.user.group_id, ...)`). Every query below is implicitly
  filtered by `group_id`. In this database all five users are in `Home`, so group scoping cannot be
  observed from the row counts alone — it still has to be written.
- **JSON encoding.** FastAPI serializes with `ensure_ascii=False` and does not escape forward
  slashes. PHP's `json_encode` escapes both by default, which makes every pagination guide differ
  (`\/groups\/labels`). `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` is applied once, as
  response middleware, rather than at each call site.
- **Two different 404 bodies.** An unrouted path is `{"detail": "Not Found"}`, but a route that
  found nothing through `HttpRepo.get_one` (`mealie/routes/_base/mixins.py:110-113`) is
  `{"detail": {"message": "Not found.", "error": true, "exception": null}}`. Labels, reports and AI
  providers all use the second; `/groups/members/{x}` and `/groups/households/{x}` raise their own
  bare-string details instead. Three shapes in one module.
- **The pagination envelope is snake_case and its contents are camelCase.** `PaginationBase`
  (`mealie/schema/response/pagination.py:51`) is a plain `BaseModel`, so the keys are `page`,
  `per_page`, `total`, `total_pages`, `items`, `next`, `previous`. The objects inside `items` are
  `MealieModel`s and are camelCased. Both appear in one response. Query parameters go the other
  way: `PaginationQuery` (`pagination.py:46`) is a `MealieModel`, so clients send `perPage`,
  `orderBy`, `orderDirection`, `queryFilter`, `orderByNullPosition`, `paginationSeed`.
- **`next`/`previous` have an exact, reproducible shape** (`pagination.py:60-94`, verified):
  `<route>?orderBy=None&orderByNullPosition=None&orderDirection=desc&queryFilter=None&paginationSeed=None&page=<n>&perPage=<n>`.
  Two details matter. The route comes from `router.url_path_for(...)` on the **sub-router**, so it
  has **no `/api` prefix** — `/groups/labels`, not `/api/groups/labels`. And unset parameters are
  Python `None` passed through `urlencode`, so they appear as the literal string `None`, not as
  empty or omitted. `next` is null on the last page, `previous` on the first.

**The token `run.sh` mints belongs to `bob`, whose five permission bits are all 0**
(`admin`, `can_manage`, `can_invite`, `can_organize`, `can_manage_household`). Nine of the 27 call
`self.checks.can_manage()` (`mealie/routes/_base/checks.py:28-31`), which raises a bare
**403 `{"detail":"Forbidden"}`**. They are still implemented; the check is what is being reproduced,
not the status.

**Corrected against the oracle: the 403 does not always win.** `checks.can_manage()` runs *inside*
the handler, and FastAPI validates the request body *before* calling it. So on the five guarded
routes that take a body, a malformed body is a **422 even for a caller who would have been refused
with a 403**. Only the three guarded routes with no body (`GET .../settings`,
`GET .../providers/{id}`, `DELETE .../providers/{id}`) answer 403 unconditionally. Writing the
permission check first is the natural mistake here and produces the wrong status on five of nine.

### 3.1–3.2 Group AI provider settings

`controller_group_ai_providers.py:21`, `APIRouter(prefix="/groups/ai-providers/settings")`,
`GroupAIProviderSettingsController(BaseUserController)`.

| # | Route | Python | Behaviour |
|---|---|---|---|
| 3.1 | `GET /api/groups/ai-providers/settings` | `:27` | `checks.can_manage()` then `group_ai_provider_settings.get_one(group_id)` |
| 3.2 | `PUT /api/groups/ai-providers/settings` | `:33` | `checks.can_manage()` then update |

- **Schema** — `AIProviderSettingsOut`, a `MealieModel`. As seen nested in `GET /groups/self`:
  `defaultProviderId`, `audioProviderId`, `imageProviderId`, `providers` (each `{id, name}`),
  `aiEnabled`, `audioProviderEnabled`, `imageProviderEnabled`.
- **Who** — `can_manage`. For `bob`: **403**.
- **IDs** — provider ids dashed.
- **Not implemented** — nothing; both reproduce the permission check and, for a permitted caller,
  the read/update. **API keys are never echoed** — `AIProviderOut` is what the settings embed, and
  the settings response carries only `{id, name}` per provider.

### 3.3–3.8 Group AI providers

`controller_group_ai_providers.py:22`, `APIRouter(prefix="/groups/ai-providers/providers")`. All six
call `checks.can_manage()` first, so all six are **403** for `bob`.

| # | Route | Python | Notes |
|---|---|---|---|
| 3.3 | `POST /api/groups/ai-providers/providers` | `:46` | create; body `AIProviderCreate` |
| 3.4 | `POST /api/groups/ai-providers/providers/test` | `:52` | tests an **unsaved** configuration; builds an ephemeral `AIProviderOut` with a fresh uuid4 that is never persisted |
| 3.5 | `POST /api/groups/ai-providers/providers/{provider_id}/test` | `:70` | tests a saved provider, optionally with unsaved `overrides`. An `overrides.apiKey` of `""` means *keep the stored key* (`:84`), not *test with an empty key* |
| 3.6 | `GET /api/groups/ai-providers/providers/{provider_id}` | `:100` | |
| 3.7 | `PUT /api/groups/ai-providers/providers/{provider_id}` | `:106` | |
| 3.8 | `DELETE /api/groups/ai-providers/providers/{provider_id}` | `:112` | returns the deleted provider |

- **Route order** — `/test` is a literal path that must be declared before `/{provider_id}`, and
  `/{provider_id}/test` before `/{provider_id}`. FastAPI prefers literals regardless of order;
  Laravel does not.
- **`provider_id` is `UUID4`** → a non-v4 value is a **422** before the handler, which is what the
  zero UUID in the shared check produces.
- **Not implemented** — the outbound call in 3.4 and 3.5. `OpenAIService.test_connection` reaches a
  third-party API; the PHP implements the permission check and the 422/404 paths and does not make
  the request. Recorded, not stubbed as a fake success.

### 3.9 `GET /api/groups/households`

- **Python** — `controller_group_households.py:14`, `UserAPIRouter(prefix="/groups/households")`.
- **Schema** — `PaginationBase[HouseholdSummary]`. An item is
  `{groupId, name, id, slug, preferences{...}}`; `preferences` is itself camelCase and carries its
  own `id` (`privateHousehold`, `showAnnouncements`, `lockRecipeEditsFromOtherHouseholds`,
  `firstDayOfWeek`, `recipePublic`, `recipeShowNutrition`, `recipeShowAssets`,
  `recipeLandscapeView`, `recipeDisableComments`, `id`).
- **Who** — any authenticated user; no `checks` call.
- **Not implemented** — nothing.

### 3.10 `GET /api/groups/households/{household_slug}`

- **Python** — `controller_group_households.py:21`. The parameter is typed `str` and resolved by
  `get_by_slug_or_id`, so **either** a slug or a UUID works and a malformed value is a 404 rather
  than a 422.
- **Not found** — **404** `{"detail": "Household not found"}` — a bare string detail.
- **Not implemented** — nothing.

### 3.11 `GET /api/groups/self`

- **Python** — `controller_group_self_service.py:25`, `UserAPIRouter(prefix="/groups")`.
- **Schema** — `GroupSummary`: `{name, id, slug, preferences{...}, aiProviderSettings{...}}`.
  Note this response embeds the AI provider settings that §3.1 guards behind `can_manage` — here
  they are returned to any member of the group, with no permission check.
- **Not implemented** — nothing.

### 3.12 `GET /api/groups/members`

- **Python** — `controller_group_self_service.py:30`.
- **Schema** — `PaginationBase[UserSummary]`; an item is `{id, groupId, householdId, username,
  fullName}` — five keys only, no email and no permission bits.
- **Route for the guides** — `/groups/members`.
- **Not implemented** — nothing.

### 3.13 `GET /api/groups/members/{username_or_id}`

- **Python** — `controller_group_self_service.py:37`. The handler tries `UUID(username_or_id)` and
  looks up by `id` on success, by `username` on `ValueError` (`:41-47`) — so **any** non-UUID string
  is treated as a username rather than rejected, and note it accepts any UUID version here, unlike
  the `UUID4`-typed parameters elsewhere.
- **Not found** — **404** `{"detail": "User Not Found"}`, a bare string detail.
- **Scoping** — the lookup goes through the group-scoped repo, so a user in another group is a 404.
- **Not implemented** — nothing.

### 3.14 `GET /api/groups/preferences`

- **Python** — `controller_group_self_service.py:53`. No permission check.
- **Schema** — `ReadGroupPreferences`: `{privateGroup, showAnnouncements, groupId, id}`.
- **Not implemented** — nothing.

### 3.15 `PUT /api/groups/preferences`

- **Python** — `controller_group_self_service.py:57`. `checks.can_manage()` → **403** for `bob`.
- **Body** — `UpdateGroupPreferences`, camelCase.
- **Side effect** — writes `group_preferences`. Excluded from the self-check's success path.
- **Not implemented** — nothing.

### 3.16 `GET /api/groups/storage`

- **Python** — `controller_group_self_service.py:62` → `GroupService.calculate_group_storage()`.
- **Schema** — `GroupStorage`: `{usedStorageBytes, usedStorageStr, totalStorageBytes,
  totalStorageStr}`. `totalStorageBytes` is a fixed quota (524288000 here);
  `usedStorageBytes` is the size of the group's directory on disk, and the `*Str` fields are
  human-readable renderings of the same numbers.
- **Where the bytes come from, corrected against the oracle** — not a `groups/` directory.
  `GroupService.calculate_group_storage` (`mealie/services/group_services/group_service.py:57-74`)
  sums `get_dir_size()` over `<data>/recipes/<recipe id>` for **every recipe in the group, across
  all households**, with the id in dashed form. `get_dir_size`
  (`mealie/pkgs/stats/fs_stats.py:25-40`) counts the directory entry itself as well as its
  contents, so a group whose recipe directories are empty still reports a few hundred bytes (416
  here). `totalStorageBytes` is the fixed `ALLOWED_SIZE`, 524288000.
- **`*Str` formatting** — `pretty_size` (`fs_stats.py:8-22`) is `round(value, 2)` rendered by
  Python's float repr, which always keeps a decimal place: `500.0 MB`, not `500 MB`. Below 1024 it
  is `<n> bytes` with no unit scaling.
- **Not implemented** — nothing.

### 3.17 `POST /api/groups/migrations`

- **Python** — `controller_migrations.py:30`, `UserAPIRouter(prefix="/groups/migrations")`.
- **Body** — `multipart/form-data`: `archive` (file, required), `migration_type` (required, one of
  the `SupportedMigrations` values), `add_migration_tag` (bool, default false). A missing file or
  type is a **422** before the handler.
- **Not implemented** — every migrator (Chowdown, CopyMeThat, Mealie alpha, Nextcloud, Paprika,
  Tandoor, PlanToEat, …). The PHP implements the multipart validation and stops; it does not unpack
  archives or write recipes. This is a large deliberate gap and is the single biggest "not
  implemented" in the round.

### 3.18–3.20 Group reports

`controller_group_reports.py:13`, `APIRouter(prefix="/groups/reports")`,
`GroupReportsController(BaseUserController)` — authenticated, no `checks` call.

| # | Route | Python | Notes |
|---|---|---|---|
| 3.18 | `GET /api/groups/reports` | `:35` | optional `report_type` query, filtered on `category`; returns a **bare JSON array** of `ReportSummary`, not a pagination envelope. `[]` on this database |
| 3.19 | `GET /api/groups/reports/{item_id}` | `:39` | `item_id` is `UUID4`; unknown id → 404 via `HttpRepo.get_one` |
| 3.20 | `DELETE /api/groups/reports/{item_id}` | `:43` | success → `SuccessResponse` `{"message": "<group.report-deleted>", "error": false}`; any failure → **500** `{"detail": {"message": "Failed to delete report", "error": true, "exception": null}}` |

- **3.20's failure mode is a 500, not a 404.** `delete_one` on a missing id raises, and the handler
  catches every exception and answers 500. Deleting an id that does not exist is therefore a 500
  here, where §3.19 on the same id is a 404.
- **Not implemented** — nothing.

### 3.21–3.25 Multi-purpose labels

`controller_labels.py:21`, `APIRouter(prefix="/groups/labels", route_class=MealieCrudRoute)`,
`MultiPurposeLabelsController(BaseCrudController)` — authenticated, **no permission check**, so
`bob` can create and delete labels.

| # | Route | Python | Notes |
|---|---|---|---|
| 3.21 | `GET /api/groups/labels` | `:44` | `PaginationBase[MultiPurposeLabelSummary]`, plus an optional `search` query. Item: `{name, color, groupId, id}` |
| 3.22 | `POST /api/groups/labels` | `:55` | body `MultiPurposeLabelCreate`; returns `MultiPurposeLabelOut` |
| 3.23 | `GET /api/groups/labels/{item_id}` | `:71` | `UUID4`; unknown → 404 |
| 3.24 | `PUT /api/groups/labels/{item_id}` | `:75` | |
| 3.25 | `DELETE /api/groups/labels/{item_id}` | `:87` | returns the deleted label |

- **`MealieCrudRoute`** (`mealie/routes/_base/routers.py:27-50`) adds a `last-modified` header and
  `Cache-Control: no-cache, no-store, must-revalidate` whenever the response body is an object with
  an `updatedAt` key. `MultiPurposeLabelOut` has no `updatedAt`, so on these five routes the wrapper
  is inert — worth stating so the headers are not added speculatively.
- **Side effects** — 3.22, 3.24 and 3.25 write. Their rejection paths are safe; the success paths
  are excluded from the self-check.
- **Not implemented** — the `label_created` / `label_updated` / `label_deleted` event-bus dispatches
  (`controller_labels.py:62-68`, `:78-84`, `:91-97`). They notify webhooks and Apprise; no part of
  the event bus is implemented in this rewrite. The HTTP response is unaffected.

### 3.26–3.27 Seeders

`controller_seeder.py:11`, `APIRouter(prefix="/groups/seeders")` — authenticated, no permission
check.

| # | Route | Python |
|---|---|---|
| 3.26 | `POST /api/groups/seeders/foods` | `:28` |
| 3.27 | `POST /api/groups/seeders/units` | `:32` |

- **Body** — `SeederConfig`: `{"locale": str}`. Missing body → **422**.
- **Responses** — success `SuccessResponse` `{"message": "Seeding Successful", "error": false}`;
  any exception → **500** `{"detail": {"message": "Seeding Failed", "error": true, "exception": null}}`.
- **Not implemented** — the seeding itself, which bulk-inserts the packaged food and unit lists for
  a locale. Both endpoints implement the body validation and stop before writing; a request that
  would have seeded is reported rather than answered with a false success. This also protects the
  shared database, which these two would otherwise fill with hundreds of rows.

### Out of scope for round 3

- `mealie/routes/households/` — round 4 and 5, including its own `/households/...` preferences and
  members, which are distinct from the group-level ones here.
- The event bus (webhooks, Apprise) behind labels and other writes.
- Third-party AI calls (§3.4, §3.5) and every data migrator (§3.17).
- Seeding data (§3.26, §3.27).

### Database safety for this round

Writing endpoints: §3.2, §3.3, §3.7, §3.8, §3.15, §3.20, §3.22, §3.24, §3.25, and §3.26/§3.27 had
they been implemented. Nine of them are `can_manage`-guarded and so are 403 for the parity token.
The self-check exercises rejection paths only, and `dev/data/mealie.db` is compared against
`dev/data/mealie.w3-snapshot.db` after the run.

---

## Round 4 — `households` core

35 endpoints from `mealie/routes/households/`, covering cookbooks, event notifications, recipe
actions, household self-service, invitations and webhooks. Shopping lists and meal plans are round 5.

Every router carries its full `/households/...` prefix and `__init__.py:15` includes them without
adding one, as in round 3. Every controller extends `BaseUserController` or `BaseCrudController`, so
**all 35 require a user** regardless of the router type.

Scoping differs from round 3 in a way that matters: `self.repos` here is filtered by **both**
`group_id` and `household_id` (`base_controllers.py:155-157`), so these collections are
household-local — except where a handler deliberately widens it, which §4.1 and §4.4 do.

In this database `cookbooks`, `webhooks`, `group_event_notifiers` and `group_recipe_actions` are all
**empty**, so every list endpoint returns `{"page":1,"per_page":n,"total":0,"total_pages":0,
"items":[],"next":null,"previous":null}` and every `/{item_id}` is a 404.

`bob`'s permission bits are all 0 (round 3), so `can_manage`, `can_manage_household`, `can_invite`
and `admin` all fail for the parity token. Remember the conventions rule: those checks run inside
the handler, so a bad path parameter or body is a 422 *before* the 403.

### 4.1–4.6 Cookbooks

`controller_cookbooks.py:24`, `APIRouter(prefix="/households/cookbooks", route_class=MealieCrudRoute)`,
`GroupCookbookController(BaseCrudController)` — authenticated, **no permission check** on any route.

| # | Route | Python | Notes |
|---|---|---|---|
| 4.1 | `GET /api/households/cookbooks` | `:51` | `CookBookPagination`. Uses `group_cookbooks` (`:33-35`), a **group-scoped** repo with `household_id=None`, so it lists other households' cookbooks too — the comment at `:52` says so explicitly |
| 4.2 | `POST /api/households/cookbooks` | `:62` | **201**; casts to `SaveCookBook` with the caller's group and household |
| 4.3 | `PUT /api/households/cookbooks` | `:78` | bulk update. Body is a **JSON array** of `UpdateCookBook`, not an object; returns an array of `ReadCookBook` |
| 4.4 | `GET /api/households/cookbooks/{item_id}` | `:104` | `UUID4 \| str`: tries `UUID(item_id)` and falls back to `slug` (`:105-113`). Also uses the **group-scoped** repo. Not found → **404 with no detail body** (`raise HTTPException(status_code=404)` at `:116`), which renders as `{"detail":"Not Found"}` — not the `ErrorResponse` object `HttpRepo` produces |
| 4.5 | `PUT /api/households/cookbooks/{item_id}` | `:122` | plain `str`; three outcomes, see below |
| 4.6 | `DELETE /api/households/cookbooks/{item_id}` | `:136` | plain `str`; two outcomes, see below |

- **Two different id types on the same path, and three different failure modes.** §4.4 accepts
  `UUID4 | str` and falls back to a slug lookup. §4.5 and §4.6 accept a plain `str` and the repo
  matches on **id only** — there is no slug fallback, so a non-UUID reaches the `GUID` type and
  raises `ValueError: badly formed hexadecimal UUID string`. What happens next depends on which
  `HttpRepo` method is involved, and this was measured against the oracle rather than inferred:

  | Request | Result |
  |---|---|
  | `GET /{non-uuid}` or `GET /{uuid not found}` | **404** `{"detail":"Not Found"}` — no detail object |
  | `PUT` with a body failing `CreateCookBook` | **422**, before the handler |
  | `PUT /{non-uuid}` with a valid body | **500**. `HttpRepo.update_one` (`mixins.py:118`) calls `repo.get_one` *outside* its try block, so the `ValueError` escapes unhandled |
  | `PUT /{uuid not found}` with a valid body | **404** `ErrorResponse` `"Not found."` |
  | `DELETE /{non-uuid}` | **400**. `delete_one` (`mixins.py:149`) *does* wrap the call, so `handle_exception` (`mixins.py:71-95`) maps the `ValueError` to its catch-all branch: `{"message":"An unexpected error occurred","error":true,"exception":"<the ValueError and the failing SQL>"}` |
  | `DELETE /{uuid not found}` | **404**, the `NoResultFound` branch — same `"An unexpected error occurred"` message, `exception` of `"No row was found when one was required"` |

  Three different 404 bodies now exist on this one path: the bare `"Not Found"`, the `"Not found."`
  `ErrorResponse`, and the `"An unexpected error occurred"` one. The `exception` field of the 400
  carries the full failing SQL statement, which is not meaningfully reproducible; the status and
  message are.
- **Not implemented** — the `cookbook_created` / `cookbook_updated` / `cookbook_deleted` event-bus
  dispatches. No effect on the HTTP response.

### 4.7–4.12 Event notifications

`controller_group_notifications.py:31`, `APIRouter(prefix="/households/events/notifications",
route_class=MealieCrudRoute)`, `GroupEventsNotifierController(BaseUserController)` — authenticated,
no permission check.

| # | Route | Python | Notes |
|---|---|---|---|
| 4.7 | `GET /api/households/events/notifications` | `:54` | `GroupEventPagination` |
| 4.8 | `POST /api/households/events/notifications` | `:64` | **201** |
| 4.9 | `GET /api/households/events/notifications/{item_id}` | `:69` | `UUID4`; `HttpRepo` 404 shape |
| 4.10 | `PUT /api/households/events/notifications/{item_id}` | `:73` | if the body's `appriseUrl` is null, the **stored** one is kept (`:74-78`) — the URL holds credentials and is never echoed back, so a client that cannot see it cannot resend it |
| 4.11 | `DELETE /api/households/events/notifications/{item_id}` | `:83` | **204, empty body** |
| 4.12 | `POST /api/households/events/notifications/{item_id}/test` | `:91` | **204, empty body** |

- **Two of the six return 204 with no content**, where the rest of this module returns the object.
- **`apprise_url` is write-only.** `GroupEventNotifierOut` omits it; only the private schema carries
  it. The PHP must never include it in a response.
- **Not implemented** — §4.12's actual dispatch: it builds an `Event` and pushes it through the
  event bus to Apprise. The 204 and the 404-for-unknown-id are implemented; nothing is sent.

### 4.13–4.18 Recipe actions

`controller_group_recipe_actions.py:25`, `APIRouter(prefix="/households/recipe-actions")`,
`GroupRecipeActionController(BaseUserController)` — authenticated, no permission check.

| # | Route | Python | Notes |
|---|---|---|---|
| 4.13 | `GET /api/households/recipe-actions` | `:41` | `GroupRecipeActionPagination` |
| 4.14 | `POST /api/households/recipe-actions` | `:51` | **201** |
| 4.15 | `GET /api/households/recipe-actions/{item_id}` | `:56` | `UUID4` |
| 4.16 | `PUT /api/households/recipe-actions/{item_id}` | `:60` | |
| 4.17 | `DELETE /api/households/recipe-actions/{item_id}` | `:64` | returns the deleted action |
| 4.18 | `POST /api/households/recipe-actions/{item_id}/trigger/{recipe_slug}` | `:71` | **202**; body `{"recipeScale": <float>}` embedded, default 1 |

- **§4.18's order of checks** (`:75-96`): unknown action → **404** `ErrorResponse` "Not found.";
  an action whose `action_type` is not `post` → **400** `ErrorResponse`
  `Cannot trigger action type "<type>".`; unknown recipe → **404**. Only then does it fire.
- **Not implemented** — the outbound HTTP POST in §4.18. It sends recipe data to a user-configured
  URL through `safehttp`. The three rejection paths above are implemented; the request is not made.

### 4.19–4.25 Household self-service

`controller_household_self_service.py:17`, `UserAPIRouter(prefix="/households")`.

| # | Route | Python | Permission |
|---|---|---|---|
| 4.19 | `GET /api/households/self` | `:26` | any member |
| 4.20 | `GET /api/households/self/recipes/{recipe_slug}` | `:31` | any member |
| 4.21 | `GET /api/households/members` | `:40` | **`can_manage`** → 403 for `bob` |
| 4.22 | `GET /api/households/preferences` | `:51` | any member |
| 4.23 | `PUT /api/households/preferences` | `:55` | **`can_manage_household`** → 403 |
| 4.24 | `PUT /api/households/permissions` | `:61` | **`can_manage`** → 403 |
| 4.25 | `GET /api/households/statistics` | `:86` | any member |

- **4.19 `HouseholdInDB`** (verified): `{groupId, name, id, slug, preferences{…}, group, users[],
  webhooks[]}`. `users` items carry only `{id, fullName}` — two keys, not the five of round 3's
  `UserSummary`. `group` is the group's **name** as a string.
- **4.21 returns `UserOut`**, the full user object of round 2 §2.2, not a summary — and it narrows
  the page query by appending `household_id=<id>` to `queryFilter` (`:44`) rather than relying on
  repo scoping.
- **4.23 is the only route in the module gated on `can_manage_household`** rather than `can_manage`.
  Getting these two mixed up produces the right status for `bob` by luck and the wrong one for a
  user who has one but not the other.
- **4.24's order of checks** (`:63-77`): `can_manage` first, then unknown user → **404** `"User not
  found"`; a user in another group → **403** `"User is not a member of this group"`; in another
  household → **403** `"User is not a member of this household"`; **yourself** → **403** `"User is
  not allowed to change their own permissions"`. All four details are bare strings.
- **4.25 `HouseholdStatistics`** (verified): `{totalRecipes, totalUsers, totalCategories,
  totalTags, totalTools}`. Counts are **group-wide for recipes/categories/tags/tools** and
  household-scoped for users — read `HouseholdService.calculate_statistics` rather than assuming.
- **Not implemented** — nothing beyond the writes noted below.

### 4.26–4.28 Invitations

`controller_invitations.py:18`, `APIRouter(prefix="/households/invitations")`.

| # | Route | Python | Notes |
|---|---|---|---|
| 4.26 | `GET /api/households/invitations` | `:23` | **admin only**, by an explicit `if not self.user.admin` → **403** `"Only admins can list invite tokens"`. Returns a **bare array**, not a pagination envelope — it pages internally with `per_page=-1` and returns `.items` |
| 4.27 | `POST /api/households/invitations` | `:33` | **201** |
| 4.28 | `POST /api/households/invitations/email` | `:62` | |

- **4.27's order** (`:35-55`): `ALLOW_PASSWORD_LOGIN` false → **403** `"Invitation tokens are
  disabled when password login is not allowed"`; `can_invite` false → **403** `"User is not allowed
  to create invite tokens"`; a non-admin naming another group or household → **403** `"Only admins
  can create invite tokens for other groups or households"`. On this deployment
  `ALLOW_PASSWORD_LOGIN` is **true**, so the first guard does not fire and `bob` stops at the
  second.
- **4.28's order** (`:68-79`): the same two guards with different wording — `"Invitation email are
  disabled when password login is not allowed"` (sic) and `"This user can't send email
  invitations"`.
- **4.28 never fails on a send error.** It catches every exception and returns **200**
  `{"success": false, "error": "<message>"}` (`:84-90`). A caller who may invite but has no SMTP
  gets a 200, not a 500.
- **Not implemented** — the email send in §4.28 and the token row insert in §4.27. Both guards are
  implemented; `bob` cannot reach either body.

### 4.29–4.35 Webhooks

`controller_webhooks.py:15`, `APIRouter(prefix="/households/webhooks")`,
`ReadWebhookController(BaseUserController)` — authenticated, no permission check.

| # | Route | Python | Notes |
|---|---|---|---|
| 4.29 | `GET /api/households/webhooks` | `:28` | `WebhookPagination` |
| 4.30 | `POST /api/households/webhooks` | `:38` | **201** |
| 4.31 | `POST /api/households/webhooks/rerun` | `:43` | literal path — must be declared before `/{item_id}`. Returns **null** with 200; the handler has no return |
| 4.32 | `GET /api/households/webhooks/{item_id}` | `:51` | `UUID4` |
| 4.33 | `POST /api/households/webhooks/{item_id}/test` | `:55` | 404 for an unknown id, then queues a background task and returns **null** with 200 |
| 4.34 | `PUT /api/households/webhooks/{item_id}` | `:60` | |
| 4.35 | `DELETE /api/households/webhooks/{item_id}` | `:64` | returns the deleted webhook |

- **Two routes return a bare `null` body with 200** (§4.31, §4.33), which Laravel renders as `{}`
  unless the body is set explicitly — the same defect round 2 hit on `forgot-password`.
- **Not implemented** — the outbound webhook POSTs in §4.31 and §4.33. `post_group_webhooks` and
  `post_test_webhook` send to user-configured URLs. §4.33's 404 path is implemented; neither sends.

### Out of scope for round 4

- `controller_shopping_lists.py`, `controller_mealplan.py`, `controller_mealplan_rules.py` — round 5.
  Note `__init__.py:26-28` says the meal-plan rules router **must** be included before the meal-plan
  router; that ordering carries into round 5's route file.
- The event bus in every form: Apprise notifications (§4.12), webhooks (§4.31, §4.33), recipe-action
  POSTs (§4.18), cookbook events.
- Email delivery (§4.28).

### Database safety for this round

Writing endpoints: §4.2, §4.3, §4.5, §4.6, §4.8, §4.10, §4.11, §4.14, §4.16, §4.17, §4.23, §4.24,
§4.27, §4.30, §4.34, §4.35. Of these, §4.23, §4.24 and §4.27 are permission-guarded and so are 403
for the parity token; the rest are reachable and are exercised only through their 404 and 422 paths.
`dev/data/mealie.db` is compared against `dev/data/mealie.w3-snapshot.db` after the run.

---

## Round 5 — `households/shopping` and `households/mealplans`

30 endpoints from `controller_shopping_lists.py` (two routers), `controller_mealplan.py` and
`controller_mealplan_rules.py`. All authenticated through `BaseCrudController` /
`BaseUserController`; no permission checks anywhere in this round.

### Route order is load-bearing

`mealie/routes/households/__init__.py:26-28` carries a comment saying the meal-plan **rules** router
must be included before the meal-plan router. The reason: `/households/mealplans/rules` would
otherwise be captured by `/households/mealplans/{item_id}`. The same applies in PHP, and to
`/mealplans/today` and `/mealplans/random`, and to `/shopping/items/create-bulk` ahead of
`/shopping/items/{item_id}`.

### Four things measured against the oracle

- **`/households/shopping/items` returns a pagination guide pointing at
  `/households/shopping/lists`.** `ShoppingListItemController.get_all`
  (`controller_shopping_lists.py:119`) calls `router.url_path_for("get_all")`, but `router` is the
  **lists** router, defined 37 lines further down at `:156`; the items router is `item_router`.
  Python resolves the global at call time, so the items endpoint advertises the lists path.
  Verified: `GET /households/shopping/items?page=2&perPage=1` returns
  `"previous": "/households/shopping/lists?...&page=1&perPage=1"`. This is an upstream defect and
  the rewrite reproduces it; "fixing" it would fail parity.
- **`GET /households/mealplans` never sets pagination guides at all.** It returns
  `self.repo.page_all(pagination=q)` directly (`controller_mealplan.py:101`) with no
  `set_pagination_guides` call, so `next` and `previous` are **always null**, on every page. Every
  other paginated endpoint in the API sets them. Verified.
- **`/households/mealplans/{item_id}` is an `int`**, not a UUID (`controller_mealplan.py:183`,
  `:187`, `:212`) — `group_meal_plans.id` is an INTEGER primary key. A non-integer is a **422**
  `int_parsing`. Meanwhile `/households/mealplans/rules/{item_id}` is a `UUID4`. Two id types under
  the same path prefix.
- **Timestamps carry `Z`.** `NaiveDateTime.process_result_value`
  (`mealie/db/models/_model_utils/datetime.py:43-50`) puts `UTC` back on every value as it is read,
  so pydantic emits `2026-07-30T22:36:59.914360Z`. Microseconds are dropped when zero.
  **But not everywhere**: `/api/recipes` emits `+00:00` for the same kind of column. The two forms
  are not interchangeable and the correct one has to be observed per endpoint rather than assumed.
  Round 6 revisits this for recipes.

### 5.1–5.8 Shopping list items

`controller_shopping_lists.py:38`, `APIRouter(prefix="/households/shopping/items")`,
`ShoppingListItemController(BaseCrudController)`.

| # | Route | Python | Notes |
|---|---|---|---|
| 5.1 | `GET /api/households/shopping/items` | `:115` | `ShoppingListItemPagination`; guide path is wrong, see above |
| 5.2 | `POST /api/households/shopping/items/create-bulk` | `:121` | **201**; body is a **JSON array** of `ShoppingListItemCreate` |
| 5.3 | `POST /api/households/shopping/items` | `:127` | **201**; delegates to §5.2 with a single-element list, so its response is the same **collection** object, not a single item |
| 5.4 | `GET /api/households/shopping/items/{item_id}` | `:131` | `UUID4`; returns a single `ShoppingListItemOut` |
| 5.5 | `PUT /api/households/shopping/items` | `:135` | bulk update; body is a JSON array of `ShoppingListItemUpdateBulk` |
| 5.6 | `PUT /api/households/shopping/items/{item_id}` | `:141` | delegates to §5.5, so again the response is a **collection**, not the single item the path suggests |
| 5.7 | `DELETE /api/households/shopping/items` | `:145` | ids come from a repeated **query** parameter `ids`, not a body; returns `SuccessResponse` |
| 5.8 | `DELETE /api/households/shopping/items/{item_id}` | `:151` | delegates to §5.7; returns `SuccessResponse`, not the deleted item |

- **Four of the eight return something other than what the path shape implies** — §5.3, §5.6
  return collections; §5.7, §5.8 return `{"message": "", "error": false}`.
- **`ShoppingListItemOut`** (verified, 20 keys in order): `quantity`, `unit`, `food`,
  `referencedRecipe`, `note`, `display`, `shoppingListId`, `checked`, `position`, `foodId`,
  `labelId`, `unitId`, `extras`, `id`, `groupId`, `householdId`, `label`, `recipeReferences`,
  `createdAt`, `updatedAt`. `unit` and `food` are **fully embedded objects**, not ids — the same
  shapes round 8 owns, which means round 5 has to serialize them first.
- **Not implemented** — every write. The bulk create/update/delete paths go through
  `ShoppingListService`, which recalculates item merging and label positions; reproducing that is
  out of scope for this round and is reported rather than approximated.

### 5.9–5.17 Shopping lists

`controller_shopping_lists.py:156`, `APIRouter(prefix="/households/shopping/lists")`.

| # | Route | Python | Notes |
|---|---|---|---|
| 5.9 | `GET /api/households/shopping/lists` | `:176` | `PaginationBase[ShoppingListSummary]` — the **summary**, with no `listItems` |
| 5.10 | `POST /api/households/shopping/lists` | `:186` | **201** |
| 5.11 | `GET /api/households/shopping/lists/{item_id}` | `:200` | `UUID4`; `ShoppingListOut`, which **does** carry `listItems` |
| 5.12 | `PUT /api/households/shopping/lists/{item_id}` | `:204` | |
| 5.13 | `DELETE /api/households/shopping/lists/{item_id}` | `:217` | returns the deleted list |
| 5.14 | `PUT /api/households/shopping/lists/{item_id}/label-settings` | `:234` | body is a JSON array; any element whose `shoppingListId` differs from the path id → **400** with the bare string ``object <id> has an invalid shopping list id`` |
| 5.15 | `POST /api/households/shopping/lists/{item_id}/recipe` | `:256` | body is a JSON array of `ShoppingListAddRecipeParamsBulk` |
| 5.16 | `POST /api/households/shopping/lists/{item_id}/recipe/{recipe_id}` | `:263` | **deprecated**; body optional, defaults to `{"recipeIncrementQuantity": 1}` |
| 5.17 | `POST /api/households/shopping/lists/{item_id}/recipe/{recipe_id}/delete` | `:274` | body optional; a POST that removes, not a DELETE |

- **`ShoppingListSummary` and `ShoppingListOut` differ by one key.** The list endpoint returns the
  summary (`name`, `extras`, `createdAt`, `updatedAt`, `groupId`, `userId`, `id`, `householdId`,
  `recipeReferences`, `labelSettings`); the single-read inserts `listItems` after `id`. Returning
  the full object from the list endpoint is the obvious mistake.
- **`labelSettings` items** (verified): `{shoppingListId, labelId, position, id, label{…}}`, where
  `label` is the round 3 label shape.
- **§5.17 is a POST whose purpose is deletion**, with the path ending in `/delete`. Routing it as a
  DELETE would 405.
- **Not implemented** — §5.10 and §5.12–§5.17's writes, all of which go through
  `ShoppingListService` (ingredient merging, quantity increments, label repositioning). §5.14's 400
  validation is implemented because it precedes the write.

### 5.18–5.25 Meal plans

`controller_mealplan.py:27`, `APIRouter(prefix="/households/mealplans")`.

| # | Route | Python | Notes |
|---|---|---|---|
| 5.18 | `GET /api/households/mealplans` | `:81` | optional `start_date` / `end_date` query; they are folded into `query_filter` as `date >= …`/`date <= …` (`:88-99`). **No pagination guides** |
| 5.19 | `POST /api/households/mealplans` | `:103` | **201** |
| 5.20 | `GET /api/households/mealplans/today` | `:129` | literal path, must precede `/{item_id}`. Returns a **bare array**, verified `[]` here |
| 5.21 | `POST /api/households/mealplans/random` | `:134` | literal path; picks a recipe by the household's meal-plan rules |
| 5.22 | `GET /api/households/mealplans/{item_id}` | `:182` | **`int`** |
| 5.23 | `PUT /api/households/mealplans/{item_id}` | `:186` | `int` |
| 5.24 | `DELETE /api/households/mealplans/{item_id}` | `:211` | `int` |

- `group_meal_plans` is empty in this database, so §5.18 is a zero-item envelope and §5.20 is `[]`.
- **Not implemented** — §5.19, §5.21, §5.23, §5.24. §5.21 in particular walks the rule set and
  random-selects a recipe; its result is not reproducible and is reported rather than invented.

### 5.25–5.30 Meal plan rules

`controller_mealplan_rules.py:14`, `UserAPIRouter(prefix="/households/mealplans/rules")`.

| # | Route | Python |
|---|---|---|
| 5.25 | `GET /api/households/mealplans/rules` | `:27` |
| 5.26 | `POST /api/households/mealplans/rules` | `:37` (**201**) |
| 5.27 | `GET /api/households/mealplans/rules/{item_id}` | `:42` (`UUID4`) |
| 5.28 | `PUT /api/households/mealplans/rules/{item_id}` | `:46` |
| 5.29 | `DELETE /api/households/mealplans/rules/{item_id}` | `:50` |

- Unlike §5.18, this one **does** set pagination guides, pointing at its own path. Verified.
- `group_meal_plan_rules` is empty here.
- **Not implemented** — the three writes.

### Out of scope for round 5

- `ShoppingListService` in every form: item merging, recipe ingredient expansion, quantity
  increment and decrement, label repositioning.
- Random meal-plan selection and the rule engine behind it.
- The event bus behind every shopping-list and meal-plan write.

### Database safety for this round

**Corrected after the self-check wrote to the shared database.** The assumption that an empty
object body is always rejected is wrong for two of these endpoints: `ShoppingListCreate` and
`PlanRulesCreate` have a default for every field, so `POST /households/shopping/lists` and
`POST /households/mealplans/rules` answer **201 and create a row** when sent `{}`. The round 5
self-check did exactly that and added one shopping list and one meal-plan rule before it was
noticed; the database was restored from `dev/data/mealie.w3-snapshot.db` with the oracle stopped
(`sandbox/restore-db.sh`) and the request list changed to use `parity.hurl`'s own scalar body `0`,
which is a 422 on every endpoint.

The lesson generalizes: **a probe body is only safe if the schema has a required field.** Checking
that before sending is now part of building each round's request list.

Three endpoints turn out to be implementable without writing anything, because an empty collection
is valid input that creates nothing:

| Request | Response |
|---|---|
| `POST /shopping/items/create-bulk` with `[]` | **201** `{"createdItems":[],"updatedItems":[],"deletedItems":[]}` |
| `PUT /shopping/items` with `[]` | **200**, same body |
| `DELETE /shopping/items` with no `ids` | **200** `{"message":"","error":false}` |

Everything else that writes is left unimplemented, so the reachable paths are reads, 404s, 422s and
§5.14's 400. `dev/data/mealie.db` is compared against the snapshot after every run.

---

## Round 6 — `recipe` CRUD, timeline events, bulk actions

21 endpoints: 11 from `recipe_crud_routes.py`, 6 from `timeline_events.py`, 4 from
`bulk_actions.py`. Scraping, imports, images, assets, exports and comments are round 7.

`mealie/routes/recipe/__init__.py:5-14` adds `prefix="/recipes"` when including `comments`,
`bulk_actions`, `shared_routes` and `timeline_events`, but **not** when including `exports` or
`recipe_crud_routes`, which carry `/recipes` in their own routers. As in round 2, the prefix has to
be worked out per file.

All of round 6 is on `UserAPIRouter` or a `BaseUserController`, so every route needs a user. There
are no `checks.*` calls; recipe permissions are enforced inside `RecipeService` instead and surface
through `handle_exceptions`.

### Three things measured against the oracle

- **Two datetime formats, and now the reason.** `GET /recipes/{slug}` returns
  `"createdAt": "2026-09-18T04:13:12.694890Z"`, but `GET /recipes` returns
  `"...694890+00:00"` for the same kind of column. `get_all` (`recipe_crud_routes.py:559-563`)
  bypasses FastAPI's serializer: it calls `orjson.dumps(pagination_response.model_dump(by_alias=True))`
  and returns `JSONBytes` directly, with a comment saying this is to avoid validation. `model_dump`
  leaves Python `datetime` objects in place and orjson renders them as `+00:00`, where pydantic's
  own serializer renders UTC as `Z`. `GET /recipes/suggestions` takes the same path, so it is
  `+00:00` too. Which form an endpoint uses therefore depends on whether it hands bytes back
  directly, not on the column.
- **`GET /recipes` builds its pagination guide differently from every other endpoint.** Instead of
  the `orderBy=None&…` form, it merges `q.model_dump()` with the request's actual query string and
  then **drops every None** (`:555-558`). Verified: `GET /recipes?page=1&perPage=1` returns
  `"next": "/recipes?orderDirection=desc&page=2&perPage=1"` — three parameters, not seven, and no
  literal `None`.
- **`GET /recipes/timeline/events` advertises `/timeline/events`, without the `/recipes` prefix.**
  Its `get_all` calls `router.url_path_for("get_all")` on the timeline router, whose own prefix is
  `/timeline/events` (`timeline_events.py:26`); the `/recipes` part is added by `__init__.py:14` at
  include time and `url_path_for` never sees it. Verified:
  `"next": "/timeline/events?orderBy=None&…"`. Same class of defect as round 5's shopping items,
  different cause. Reproduce, do not correct.

### 6.1–6.11 Recipe CRUD

`recipe_crud_routes.py:105`, `UserAPIRouter(prefix="/recipes", route_class=MealieCrudRoute)`.

| # | Route | Python | Notes |
|---|---|---|---|
| 6.1 | `GET /api/recipes` | `:508` | `PaginationBase[RecipeSummary]`; guides as above. Optional `categories`, `tags`, `tools`, `foods`, `households` repeated query params, plus `RecipeSearchQuery` (`cookbook`, `search`, `requireAll*`). A `cookbook` that resolves to nothing → **404** `{"detail":"cookbook not found"}` |
| 6.2 | `GET /api/recipes/suggestions` | `:565` | literal path, before `/{slug}`. `{"items":[{"recipe":{…},…}]}` |
| 6.3 | `GET /api/recipes/{slug}` | `:583` | **slug or id** (`Path` description says so); full `Recipe`, 33 keys |
| 6.4 | `POST /api/recipes` | `:594` | **201**, and the body is a **bare JSON string** — `response_model=str`, the new recipe's slug |
| 6.5 | `POST /api/recipes/{slug}/duplicate` | `:618` | **201**, returns the full `Recipe` |
| 6.6 | `PUT /api/recipes/{slug}` | `:640` | body is a whole `Recipe` |
| 6.7 | `PUT /api/recipes` | `:663` | bulk; body is a JSON array of `Recipe`; returns an array |
| 6.8 | `PATCH /api/recipes/{slug}` | `:688` | |
| 6.9 | `PATCH /api/recipes` | `:711` | bulk |
| 6.10 | `PATCH /api/recipes/{slug}/last-made` | `:736` | body `{"timestamp": …}` |
| 6.11 | `DELETE /api/recipes/{slug}` | `:760` | returns the deleted `Recipe` |

- **`handle_exceptions` (`:110-135`) is the error map for all of these**, and it is by exception
  type, not by status: `PermissionDenied` → **403** `ErrorResponse` `"Permission Denied"`;
  `NoEntryFound` → **404** `"No Entry Found"`; `IntegrityError` → **400** `"Recipe already exists"`;
  `RecursiveRecipe` → **400** with the translated `exceptions.recursive-recipe-link`. Note the 404
  message is `"No Entry Found"`, not the `"Not found."` that `HttpRepo` produces elsewhere — a
  fourth distinct 404 body in this API.
- **§6.4 returns a bare JSON string**, e.g. `"my-recipe"`, not an object. §6.5 returns an object.
- **`RecipeSummary`** (verified, 25 keys): `id`, `userId`, `householdId`, `groupId`, `name`, `slug`,
  `image`, `recipeServings`, `recipeYieldQuantity`, `recipeYield`, `totalTime`, `prepTime`,
  `cookTime`, `performTime`, `description`, `recipeCategory`, `tags`, `tools`, `rating`, `orgURL`,
  `dateAdded`, `dateUpdated`, `createdAt`, `updatedAt`, `lastMade`. The full `Recipe` adds eight
  more (ingredients, instructions, assets, settings, notes, nutrition, extras, comments).
- **Scoping**: `get_all` uses `group_recipes` (`household_id=None`) deliberately — the comment at
  `:535-537` says all households' recipes are returned and a household filter belongs in
  `queryFilter`.
- **Not implemented** — §6.4–§6.11, all of which write through `RecipeService` (slug generation,
  ingredient and instruction replacement, timeline entries, permission checks across households).
  The read paths, the 404/403/400 mapping and the cookbook 404 are implemented.

### 6.12–6.17 Timeline events

`timeline_events.py:26`, `UserAPIRouter(prefix="/timeline/events", route_class=MealieCrudRoute)`,
included with `prefix="/recipes"` → `/api/recipes/timeline/events`.

| # | Route | Python | Notes |
|---|---|---|---|
| 6.12 | `GET /api/recipes/timeline/events` | `:47` | guide path missing `/recipes`, as above |
| 6.13 | `POST /api/recipes/timeline/events` | `:61` | **201**; an unknown `recipeId` → **404** `{"detail":"recipe not found"}`, a bare string |
| 6.14 | `GET /api/recipes/timeline/events/{item_id}` | `:89` | `UUID4` |
| 6.15 | `PUT /api/recipes/timeline/events/{item_id}` | `:96` | |
| 6.16 | `DELETE /api/recipes/timeline/events/{item_id}` | `:117` | returns the deleted event |
| 6.17 | `PUT /api/recipes/timeline/events/{item_id}/image` | `:147` | multipart |

- **`RecipeTimelineEventOut`** (verified, 12 keys): `recipeId`, `userId`, `subject`, `eventType`,
  `eventMessage`, `image`, `timestamp`, `id`, `groupId`, `householdId`, `createdAt`, `updatedAt`.
- `recipe_timeline_events` has 17 rows here, so this is one of the few collections in rounds 4-6
  whose item shape can be checked against live data.
- **Not implemented** — §6.13, §6.15, §6.16, §6.17's writes, and the image handling in §6.17.

### 6.18–6.21 Bulk actions

`bulk_actions.py:23`, `APIRouter(prefix="/bulk-actions")`, included with `prefix="/recipes"` →
`/api/recipes/bulk-actions`. The export routes on this controller are round 7.

| # | Route | Python | Notes |
|---|---|---|---|
| 6.18 | `POST /api/recipes/bulk-actions/tag` | `:37` | body `AssignTags`; **returns null** — the handler has no return, and a `# TODO` at `:36` asks whether it should |
| 6.19 | `POST /api/recipes/bulk-actions/settings` | `:41` | returns null |
| 6.20 | `POST /api/recipes/bulk-actions/categorize` | `:45` | returns null |
| 6.21 | `POST /api/recipes/bulk-actions/delete` | `:49` | returns null; `PermissionDenied` → **403** `ErrorResponse` `"Permission Denied"` |

- **All four return a bare `null` with 200**, the same Laravel trap as rounds 2 and 4.
- **Not implemented** — all four mutate. Their body validation is implemented; the service calls
  are not.

### Out of scope for round 6

- Scraping and import: `/recipes/test-scrape-url`, `/recipes/create/url`, `/create/html-or-json`,
  `/create/ai`, `/create/zip`, `/create/image`, and the four `*/stream` variants — round 7.
- Images, assets, exports, `/recipes/{slug}/comments` and the shared-token routes — round 7.
- The bulk **export** routes on `bulk_actions.py` (`/export`, `/export/{id}/download`,
  `/export/purge`) — round 7.
- `RecipeService` writes in every form, and the event bus behind them.

### Database safety for this round

Every writing endpoint is left unimplemented. Per round 5's rule, each probe body in the request
list is checked against its schema for a required field first: `CreateRecipe` requires `name`,
`RecipeTimelineEventIn` requires `recipeId` and `subject`, and the three `Assign*` bodies require
`recipes`, so an empty object is a 422 on all of them and no row is created. `dev/data/mealie.db` is
compared against `dev/data/mealie.w3-snapshot.db` after the run.

---

## Round 7 — recipe import, images, assets, exports, shared links, comments

24 endpoints: 15 more from `recipe_crud_routes.py`, 2 from `exports.py`, 2 from `shared_routes.py`,
1 from `comments.py`, and the 4 export routes on `bulk_actions.py`.

### Three things measured against the oracle

- **`/api/recipes/shared/{token_id}` is anonymous.** `shared_routes.py:17` is a plain `APIRouter()`
  and `:22`/`:42` are **module-level functions**, not controller methods, so neither inherits a user
  dependency. Verified: a request with no `Authorization` header returns **404**
  `{"detail":{"message":"Token Not Found","error":true,"exception":null}}`, not a 401. Both share
  routes are public by design — they are the share-link feature.
- **`GET /api/recipes/{slug}/comments` is a 500 for an unknown slug.** `comments.py:13-14` does
  `recipe = self.repos.recipes.get_one(slug)` and then `recipe.id` with no null check, so a missing
  recipe raises `AttributeError` and Starlette answers plain-text `Internal Server Error`. Verified.
  A known slug returns `[]` here.
- **`GET /api/recipes/exports` returns `{"json":["raw"],"zip":["zip"]}`** on this deployment —
  the real template list from `TemplateService`, not an empty skeleton.

### Route order

`/recipes/shared/{token_id}` and `/recipes/exports` are literal second segments and must be
declared **before** `/recipes/{slug}`; `/recipes/{slug}/exports` is a different route from
`/recipes/exports`. `/recipes/create/*` and `/recipes/test-scrape-url` likewise precede `/{slug}`.

### 7.1–7.10 Scraping and import

All on `recipe_crud_routes.py`'s router.

| # | Route | Python | Notes |
|---|---|---|---|
| 7.1 | `POST /api/recipes/test-scrape-url` | `:158` | body `ScrapeRecipeTest`; returns the raw scraped schema, or the **bare string** `"recipe_scrapers was unable to scrape this URL"`. A scrape timeout → **408** `ErrorResponse` `"Recipe Scraping Timed Out"` |
| 7.2 | `POST /api/recipes/create/html-or-json` | `:184` | **201**, bare string slug. A body starting with `{` is converted from ld+json to HTML first |
| 7.3 | `POST /api/recipes/create/html-or-json/stream` | `:200` | **SSE**, `text/event-stream` |
| 7.4 | `POST /api/recipes/create/url` | `:213` | **201**, bare string slug |
| 7.5 | `POST /api/recipes/create/url/stream` | `:226` | SSE |
| 7.6 | `POST /api/recipes/create/ai` | `:380` | **201**, bare string slug. Every parameter is a **Form** field (`:383-387`), not a JSON body, so a JSON payload is ignored rather than rejected and the handler always runs |
| 7.7 | `POST /api/recipes/create/ai/stream` | `:409` | SSE |
| 7.8 | `POST /api/recipes/create/url/bulk` | `:451` | **202**, `{"reportId": "<uuid>"}` — the one import route that answers with an object |
| 7.9 | `POST /api/recipes/create/zip` | `:470` | **201**, multipart `archive`, bare string slug |
| 7.10 | `POST /api/recipes/create/image` | `:484` | **201**, deprecated and `include_in_schema=False`, so it is absent from the OpenAPI document but still routed. Multipart `images` (a list) plus `translateLanguage` as a **query** parameter |

- **§7.6 and §7.7 have a reachable guard**, measured: with no content, images or URL they answer
  before touching any AI provider. §7.6 is **400**
  `{"detail":{"message":"No content, images, or URL were provided",…}}`; §7.7 is **200**
  `text/event-stream` carrying `event: error` and that same message. Both are implemented; the
  branches that would call a provider are not.
- **§7.22 answers 404 `{"detail":"export not found"}`** for an unknown id — a bare string detail.
  Implemented.
- **The error path is shared**: `_create_recipe_from_web` yields SSE events, and the non-stream
  variants turn an ERROR event into **400** `ErrorResponse` with the event's message, falling
  through to **500** `"Unknown Error"` if neither DONE nor ERROR arrives (`:220-224`).
- **Not implemented** — every one of these ten. They fetch third-party URLs, run
  `recipe_scrapers`, call an AI provider, or unpack uploaded archives. The request validation is
  implemented so that a malformed body is the same 422 the oracle gives; nothing is fetched,
  parsed or written. The three `*/stream` routes would additionally need SSE, which is not
  attempted.

### 7.11–7.15 Images and assets

| # | Route | Python | Notes |
|---|---|---|---|
| 7.11 | `POST /api/recipes/{slug}/image` | `:782` | body `ScrapeRecipe` (a URL). Three distinct 400s: `"Url is not an image"`, `"Url is not from an allowed domain"`, `"Image could not be downloaded"` (`:789-806`) |
| 7.12 | `PUT /api/recipes/{slug}/image` | `:812` | multipart: `image` bytes plus `extension` as a **form** field |
| 7.13 | `DELETE /api/recipes/{slug}/image` | `:821` | `SuccessResponse` with the translated `recipe.recipe-image-deleted` |
| 7.14 | `POST /api/recipes/{slug}/assets` | `:884` | multipart: `name`, `icon`, `extension` form fields and a `file` |
| 7.15 | `POST /api/recipes/{slug}/assets/url` | `:896` | downloads an image to store as an asset; same `"Url is not an image"` / allowed-domain 400s |

- **§7.11's third 400 is deliberate**, with a comment at `:800-801`: a failed download must not
  leave the recipe claiming an image, or every render asks the media route for a missing file.
- **Not implemented** — all five. They fetch URLs through the SSRF allow-list, re-encode images and
  write to the recipe's data directory. Validation and the `handle_exceptions` mapping are
  implemented.

### 7.16–7.17 Exports

`exports.py:13`, `UserAPIRouter(prefix="/recipes")` — it carries its own prefix, unlike the four
files `__init__.py` adds one to.

| # | Route | Python | Notes |
|---|---|---|---|
| 7.16 | `GET /api/recipes/exports` | `:21` | `FormatResponse`; **verified** `{"json":["raw"],"zip":["zip"]}` |
| 7.17 | `GET /api/recipes/{slug}/exports` | `:25` | `template_name` is a **required query parameter**, so omitting it is a **422** before the handler. With `template_name=raw` the response is a `FileResponse` served as `application/json` |

- §7.16 is implementable exactly: the template list is static for this deployment.
- **§7.17 with `template_name=raw` is the recipe itself.** Verified: the response is the same JSON
  as `GET /recipes/{slug}`, served as `application/json` with four-space indentation because
  `TemplateService` writes it to a file and `FileResponse` serves that. Implemented exactly.
- **Not implemented** — §7.17's other templates. The 422 for a missing `template_name` and the 404
  for an unknown slug are implemented.

### 7.18–7.19 Shared recipe links

`shared_routes.py:17`, plain `APIRouter()`, included with `prefix="/recipes"`. **Anonymous.**

| # | Route | Python | Notes |
|---|---|---|---|
| 7.18 | `GET /api/recipes/shared/{token_id}` | `:22` | `token_id` is `UUID4`, so a non-v4 value is a **422**. An unknown or expired token → **404** `ErrorResponse` `"Token Not Found"`. An **expired** token is deleted as a side effect before the 404 (`:27-34`) |
| 7.19 | `GET /api/recipes/shared/{token_id}/zip` | `:42` | calls §7.18 first, so it inherits the same 404 and 422, then zips the recipe with its original image |

- **§7.18 writes** — it deletes expired tokens. On an unknown token nothing is deleted, which is the
  only path reachable here.
- **Not implemented** — §7.19's zip construction. §7.18 is implemented in full except that expired
  tokens are not deleted; `recipe_share_tokens` is empty in this database, so neither path is
  reachable with data.

### 7.20 Recipe comments

`comments.py:5`, `UserAPIRouter()` with no prefix, included with `prefix="/recipes"`.

- **`GET /api/recipes/{slug}/comments`** (`:10`) — authenticated; returns a **bare array** of
  `RecipeCommentOut`. An unknown slug is a **500**, as above. `recipe_comments` is empty here, so a
  known slug returns `[]`.
- **Not implemented** — nothing; both branches are reproduced, the 500 included.

### 7.21–7.24 Bulk export

`bulk_actions.py`, the remaining four routes.

| # | Route | Python | Notes |
|---|---|---|---|
| 7.21 | `POST /api/recipes/bulk-actions/export` | `:60` | **202** |
| 7.22 | `GET /api/recipes/bulk-actions/export/{export_id}/download` | `:65` | |
| 7.23 | `GET /api/recipes/bulk-actions/export` | `:76` | bare array of `GroupDataExport`; **verified** `[]` |
| 7.24 | `DELETE /api/recipes/bulk-actions/export/purge` | `:80` | **verified** `{"message":"0 exports deleted","error":false}` — the count is computed, and with no exports present nothing is written |

- **Route order within this group**: `/export/purge` is a literal path that must precede
  `/export/{export_id}/download`, and `/export` must not swallow either.
- §7.23 and §7.24 are implementable exactly. **Not implemented** — §7.21's background zip build and
  §7.22's file download.

### Out of scope for round 7

- Every outbound fetch: recipe scraping, AI providers, image downloads.
- SSE in all three streaming variants.
- Archive handling: zip import and zip export.
- Image re-encoding and asset storage.

### Database safety for this round

The only writing path reachable without an outbound fetch is §7.18's expired-token deletion, and
`recipe_share_tokens` is empty. §7.24 reports a count and deletes nothing when there is nothing to
delete, which is the state here. Every probe body is checked against its schema for a required
field first, per round 5's rule. `dev/data/mealie.db` is compared against the snapshot after the run.

---

## Round 8 — `organizers`, `unit_and_foods`, `comments`

41 endpoints: categories 8, tags 8, tools 6, foods 7, units 7, comments 5.

### Mount points differ by package

- `mealie/routes/organizers/__init__.py:5` adds `prefix="/organizers"`, and each controller adds
  its own, so these are `/api/organizers/categories`, `/api/organizers/tags`,
  `/api/organizers/tools`.
- `mealie/routes/unit_and_foods/__init__.py:5` adds **nothing**; the controllers carry `/foods` and
  `/units`, so these are `/api/foods` and `/api/units` — **not** under `/organizers` and not under
  `/recipes`.
- `mealie/routes/comments/__init__.py:19` carries `/comments` → `/api/comments`. This is separate
  from `/api/recipes/{slug}/comments`, which was round 7.

### Permissions are asymmetric, and this is the thing to get right

`bob`'s `can_organize` is 0, as all his bits are.

| Controller | Guard |
|---|---|
| categories | `checks.can_organize()` on create, merge, update, delete (`:54`, `:80`, `:102`, `:128`) |
| tags | same four (`:49`, `:69`, `:91`, `:117`) |
| foods | same four (`:51`, `:57`, `:71`, `:77`) |
| **tools** | **none** — `controller_tools.py` has no `checks.` call at all |
| **units** | **none** — `units.py` has no `checks.` call at all |
| comments | no `can_organize`; instead `_check_comment_belongs_to_user` on update and delete |

So `bob` can create, edit and delete **tools and units** but not categories, tags or foods.
Assuming the five organizer-ish collections behave alike gives the wrong status on 8 of them.

As always, request validation precedes the in-handler permission check, so a malformed body or a
non-v4 id is a 422 before any 403.

### Two measured details

- **The organizer pagination guides are missing the `/organizers` prefix.** Verified:
  `GET /api/organizers/categories?page=1&perPage=1` returns `"next": "/categories?orderBy=None&…"`.
  Same `url_path_for`-on-the-sub-router cause as rounds 5, 6. `/api/foods` and `/api/units`
  advertise `/foods` and `/units`, which happen to be right because those routers carry no extra
  prefix.
- **A category has two different shapes.** The list item is
  `{id, groupId, name, slug, recipeCount}` (5 keys) but `GET /organizers/categories/{item_id}`
  returns `CategorySummary` — `{id, slug, name}`, **3 keys, no groupId and no recipeCount**.
  Verified. Tags behave the same way via `RecipeTagResponse`.

### 8.1–8.8 Categories

`controller_categories.py:17`, `APIRouter(prefix="/categories")`, `BaseCrudController`.

| # | Route | Python | Guard |
|---|---|---|---|
| 8.1 | `GET /api/organizers/categories` | `:39` | — |
| 8.2 | `POST /api/organizers/categories` | `:51` | `can_organize` → 403 |
| 8.3 | `GET /api/organizers/categories/empty` | `:72` | — ; **verified `[]`** |
| 8.4 | `POST /api/organizers/categories/merge` | `:77` | `can_organize` |
| 8.5 | `GET /api/organizers/categories/{item_id}` | `:92` | — ; `CategorySummary`, 3 keys |
| 8.6 | `PUT /api/organizers/categories/{item_id}` | `:99` | `can_organize` |
| 8.7 | `DELETE /api/organizers/categories/{item_id}` | `:121` | `can_organize`; **returns null** — the handler has no return |
| 8.8 | `GET /api/organizers/categories/slug/{category_slug}` | `:141` | — ; embeds a full recipe list, and `groupId` comes back **null** (verified) |

- **§8.4's order** (`:79-90`): `can_organize` first, then `from_id == to_id` → **400** bare string
  `"from_id and to_id must be different"`, then each id checked separately → **404**
  `"from_id category not found"` / `"to_id category not found"`.
- **Route order** — `/empty`, `/merge` and `/slug/{x}` are literal second segments and must precede
  `/{item_id}`.
- **Not implemented** — §8.2, §8.4, §8.6, §8.7's writes. All four are `can_organize`-guarded and so
  are 403 for the parity token anyway.

- **`GET /categories/slug/{unknown}` is a 404 but `GET /tags/slug/{unknown}` is a 500**, measured.
  `controller_categories.py:141` goes through `mixins.get_one`, which raises a proper 404;
  `controller_tags.py:133` and `controller_tools.py:56` return `repo.get_one(...)` straight to
  FastAPI, which then fails to validate `None` against the response model. Three sibling
  controllers, two behaviours.

### 8.9–8.16 Tags

`controller_tags.py:16`, `APIRouter(prefix="/tags")`. The same eight shapes as categories, with
`/tags/empty` (`:41`), `/tags/merge` (`:46`, **POST**), `/tags/{item_id}` (`:61`, `:88`, `:110`),
`/tags/slug/{tag_slug}` (`:133`), `GET ""` (`:29`) and `POST ""` (`:66`).

- `tags` has 9 rows here; `/tags/empty` is **verified `[]`**.
- Guards and route-order constraints are identical to categories.

### 8.17–8.22 Tools

`controller_tools.py:14`, `APIRouter(prefix="/tools")`, `BaseUserController` — **no permission
check on any route**.

| # | Route | Python |
|---|---|---|
| 8.17 | `GET /api/organizers/tools` | `:27` |
| 8.18 | `POST /api/organizers/tools` | `:38` (**201**) |
| 8.19 | `GET /api/organizers/tools/{item_id}` | `:43` |
| 8.20 | `PUT /api/organizers/tools/{item_id}` | `:47` |
| 8.21 | `DELETE /api/organizers/tools/{item_id}` | `:52` |
| 8.22 | `GET /api/organizers/tools/slug/{tool_slug}` | `:56` |

- `tools` is empty here, so §8.17 is a zero-item envelope and the id routes are 404s.
- **Not implemented** — §8.18, §8.20, §8.21's writes. Unlike categories and tags these are
  **reachable** for `bob`, so the request list must not probe them with a body the schema accepts.

### 8.23–8.29 Foods

`unit_and_foods/foods.py:21`, `APIRouter(prefix="/foods", route_class=MealieCrudRoute)`.

| # | Route | Python | Guard |
|---|---|---|---|
| 8.23 | `GET /api/foods` | `:38` | — ; 2696 rows here |
| 8.24 | `POST /api/foods` | `:49` | `can_organize` |
| 8.25 | `PUT /api/foods/merge` | `:55` | `can_organize`; note **PUT**, where categories and tags use POST |
| 8.26 | `GET /api/foods/{item_id}` | `:65` | — |
| 8.27 | `PUT /api/foods/{item_id}` | `:69` | `can_organize` |
| 8.28 | `DELETE /api/foods/{item_id}` | `:75` | `can_organize` |

- **`IngredientFood`** is the 12-key shape round 5 already builds for shopping items:
  `id`, `name`, `pluralName`, `description`, `extras`, `labelId`, `aliases`, `substitutions`,
  `householdsWithIngredientFood`, `label`, `createdAt`, `updatedAt`.
- **Route order** — `/foods/merge` is a **PUT** on a literal path and must precede
  `PUT /foods/{item_id}`, or the merge is swallowed.
- **Not implemented** — the four guarded writes.

### 8.30–8.36 Units

`unit_and_foods/units.py:21`, `APIRouter(prefix="/units", route_class=MealieCrudRoute)` — **no
permission check on any route**, unlike foods.

Same six shapes as foods: `GET ""` (`:38`), `POST ""` (`:49`), `PUT /merge` (`:54`),
`GET /{item_id}` (`:63`), `PUT /{item_id}` (`:67`), `DELETE /{item_id}` (`:71`).

- **`IngredientUnit`** is the 14-key shape round 5 builds: the food keys minus `labelId`/`label`/
  `substitutions`/`householdsWithIngredientFood`, plus `fraction`, `abbreviation`,
  `pluralAbbreviation`, `useAbbreviation`, `standardQuantity`, `standardUnit`.
- 27 rows here.
- **Not implemented** — the three writes, which are **reachable** for `bob`. Same caution as tools.

### 8.37–8.41 Comments

`comments/__init__.py:19`, `APIRouter(prefix="/comments")`, `BaseUserController`.

| # | Route | Python | Notes |
|---|---|---|---|
| 8.37 | `GET /api/comments` | `:get_all` | `RecipeCommentPagination`; **verified** empty here |
| 8.38 | `POST /api/comments` | | **201** |
| 8.39 | `GET /api/comments/{item_id}` | | `UUID4` |
| 8.40 | `PUT /api/comments/{item_id}` | | ownership check first |
| 8.41 | `DELETE /api/comments/{item_id}` | | ownership check; returns `SuccessResponse` `"Comment deleted"`, not the deleted object |

- **`_check_comment_belongs_to_user` is itself a latent 500.** It does
  `comment = self.repo.get_one(item_id)` and then `comment.user_id` with no null check, so §8.40
  and §8.41 on an id that does not exist raise `AttributeError` before any 404 — the same defect as
  round 7's recipe comments route. A non-owner who is not an admin gets **403**
  `"Comment does not belong to user"`.
- **Not implemented** — §8.38's write. §8.40 and §8.41 reach the 500 or the 403 before writing.

### Out of scope for round 8

- `/api/recipes/{slug}/comments` — round 7.
- The event bus behind every organizer write.
- Merge semantics (re-pointing every recipe link, then deleting the source row) for all four merge
  routes.

### Database safety for this round

Categories, tags and foods are `can_organize`-guarded and so unreachable for the parity token.
**Tools, units and comments are not guarded**, so their create and update routes are reachable: the
request list probes them with `parity.hurl`'s scalar body `0` only, never with an object, and each
writing route is left unimplemented. `dev/data/mealie.db` is compared against the snapshot after the
run.

---

## Round 9 — `admin`, `explore`, `media`, `parser`, `shared`

64 endpoints: admin 38, explore 15, media 5, parser 2, shared 4.

### The rule this round sharpens

Round 3 established that request validation precedes every permission check. That is true for
checks written **inside a handler** (`self.checks.can_manage()`, `assert_user_change_allowed`). It
is **not** true for checks attached to the router as a dependency. Measured:

| Where the check lives | Example | Scalar body on that route |
|---|---|---|
| Router dependency | `AdminAPIRouter` → `Depends(get_admin_user)` | **403**, body never parsed |
| Router dependency | `UserAPIRouter` → `Depends(get_current_user)` | **401** when unauthenticated |
| Inside the handler | `checks.can_organize()`, `checks.can_manage()` | **422**, validation first |

So all 38 admin routes answer **403** for the parity token whatever is sent, while a
`can_organize` route answers 422 for the same body. The convention in this document's preamble
should be read with that distinction.

**And the 403 does not extend to paths that do not exist.** Verified:
`GET /api/admin/nonexistent-w3` with a non-admin token is **404**, not 403, because routing happens
before the dependency. A middleware that blanket-403s `/api/admin*` ahead of routing gets this
wrong — it is the difference between translating `get_admin_user` and short-circuiting the test.

### 9.1–9.38 Admin

`mealie/routes/admin/__init__.py:15`, `AdminAPIRouter(prefix="/admin")`. Every sub-router inherits
`Depends(get_admin_user)` (`routes/_base/routers.py:13-17`), which answers **401** with no token and
**403** `{"detail":"Forbidden"}` for a non-admin. `bob` is not an admin, so all 38 are 403.

| File | Count | Paths |
|---|---|---|
| `admin_about.py` | 3 | `/admin/about`, `/admin/about/statistics`, `/admin/about/check` |
| `admin_management_users.py` | 7 | `/admin/users` (GET, POST), `/admin/users/{item_id}` (GET, PUT, DELETE), `/admin/users/unlock`, `/admin/users/password-reset-token` |
| `admin_management_households.py` | 5 | `/admin/households` (GET, POST), `/admin/households/{item_id}` (GET, PUT, DELETE) |
| `admin_management_groups.py` | 5 | `/admin/groups` (GET, POST), `/admin/groups/{item_id}` (GET, PUT, DELETE) |
| `admin_management_ai_providers.py` | 4 | `/admin/ai-providers/...` |
| `admin_email.py` | 2 | `/admin/email` (GET, POST) |
| `admin_backups.py` | 6 | `/admin/backups`, `/admin/backups/upload`, `/admin/backups/{file_name}` (GET, DELETE), `/admin/backups/{file_name}/restore` |
| `admin_maintenance.py` | 5 | `/admin/maintenance`, `/admin/maintenance/storage`, `/admin/maintenance/logs`, `/admin/maintenance/clean/*` |
| `admin_debug.py` | 1 | `/admin/debug/openai` |

- **Implementation**: the paths are declared from the Python source and carry the `mealie:admin`
  middleware, which is the translation of the router dependency. Nothing behind them is
  implemented, and nothing needs to be for this caller — but the routes are real, so an undeclared
  admin path still 404s, as it does in Python.
- **Not implemented** — every admin behaviour: user and group management, backup creation and
  restore, maintenance tasks, email sending, the OpenAI debug call. For an admin caller this PHP
  would be wrong; the report must say so rather than let 38 green rows imply otherwise.

### 9.39–9.53 Explore

`mealie/routes/explore/__init__.py:12`, `APIRouter(prefix="/explore/groups/{group_slug}")`.

**Anonymous.** The controllers extend `BasePublicGroupExploreController` /
`BasePublicHouseholdExploreController` (`base_controllers.py:86`, `:106`), whose dependency is
`get_public_group`, not `get_current_user`. Verified: no token needed, 200.

- **`get_public_group`** (`dependencies.py:66-73`) resolves `group_slug` by slug **or id** and
  raises **404** `"group not found"` when the group is missing **or private**. Verified:
  `/explore/groups/nope-w3/recipes` is 404. A private group is indistinguishable from a missing one.

| # | Path under `/api/explore/groups/{group_slug}` | Python |
|---|---|---|
| 9.39–9.40 | `/foods`, `/foods/{item_id}` | `controller_public_foods.py:19`, `:34` |
| 9.41–9.42 | `/households`, `/households/{household_slug}` | `controller_public_households.py:19`, `:30` |
| 9.43–9.48 | `/organizers/categories`, `/organizers/categories/{item_id}`, and the same for `tags` and `tools` | `controller_public_organizers.py:24`-`:99` |
| 9.49–9.50 | `/cookbooks`, `/cookbooks/{item_id}` | `controller_public_cookbooks.py:22`, `:40` |
| 9.51–9.53 | `/recipes`, `/recipes/suggestions`, `/recipes/{recipe_slug}` | `controller_public_recipes.py:31`, `:92`, `:109` |

- **Route order** — `/recipes/suggestions` precedes `/recipes/{recipe_slug}`; the organizer paths
  sit under a `base_prefix` of `/organizers`.
- **Household filtering** — the household-level controllers must exclude households whose
  `private_household` is true, per `get_public_household` and `cross_household_repos`
  (`base_controllers.py:96-129`). The one household here is public.
- **Not implemented** — nothing. These are reads of data rounds 5-8 already serialize.

### 9.54–9.58 Media

`mealie/routes/media/__init__.py:8`, `APIRouter(prefix="/api/media")` — it carries the full `/api`
itself and is included at `mealie/app.py:149`, like `utility_routes.py` in round 1. **Anonymous.**

| # | Route | Python |
|---|---|---|
| 9.54 | `GET /api/media/recipes/{recipe_id}/images/{file_name}` | `media_recipe.py:19` |
| 9.55 | `GET /api/media/recipes/{recipe_id}/images/timeline/{timeline_event_id}/{file_name}` | `media_recipe.py:33` |
| 9.56 | `GET /api/media/recipes/{recipe_id}/assets/{file_name}` | `media_recipe.py:51` |
| 9.57 | `GET /api/media/users/{user_id}/{file_name}` | `media_user.py:10` |
| 9.58 | `GET /api/media/docker/validate.txt` | `media/__init__.py:14` |

- **`recipe_id` and `user_id` are `UUID4`**, so a malformed one is a **422** before the handler.
  Verified on §9.57.
- **Route order** — `/images/timeline/{id}/{file}` must precede `/images/{file_name}`, and
  `/docker/validate.txt` must not be captured by `/recipes/...` or `/users/...` (it is not, they
  have distinct first segments).
- **Not implemented** — serving the files themselves. The 422 and the 404-for-missing-file are
  implemented; no bytes are returned.

### 9.59–9.60 Parser

`parser/ingredient_parser.py:8`, `APIRouter(prefix="/parser")`, on a `UserAPIRouter`-equivalent
controller — authenticated, and a scalar body is a **422** (verified), so the auth dependency
passes and then validation rejects.

- `POST /api/parser/ingredient` (`:13`) → `ParsedIngredient`
- `POST /api/parser/ingredients` (`:19`) → a bare array of `ParsedIngredient`
- **Not implemented** — the NLP ingredient parser itself. The 422 path is.

### 9.61–9.64 Shared recipe tokens

`shared/__init__.py:14`, `UserAPIRouter(prefix="/shared/recipes")` — **authenticated**, unlike
round 7's `/api/recipes/shared/{token_id}`, which is the public side of the same feature.

| # | Route | Notes |
|---|---|---|
| 9.61 | `GET /api/shared/recipes` | bare array of `RecipeShareTokenSummary`; optional `recipe_id` query |
| 9.62 | `POST /api/shared/recipes` | **201**; a recipe outside the caller's group → **404** `"Recipe not found in your group"` |
| 9.63 | `GET /api/shared/recipes/{item_id}` | `UUID4` |
| 9.64 | `DELETE /api/shared/recipes/{item_id}` | returns **null** — the handler is typed `-> None` |

- `recipe_share_tokens` is empty here, so §9.61 is `[]` and the id routes are 404s.
- **Not implemented** — §9.62's write.

### Out of scope for round 9

- Every admin behaviour (see above).
- Serving media bytes.
- The ingredient parser.
- `mealie/routes/spa/` — excluded from this rewrite throughout.

### Database safety for this round

Admin is unreachable for the parity token, explore and media are read-only, parser does not write,
and §9.62 is left unimplemented. `dev/data/mealie.db` is compared against the snapshot after the run.
