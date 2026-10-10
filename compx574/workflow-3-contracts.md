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
