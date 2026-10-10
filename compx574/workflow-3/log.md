# Workflow 3 — Single agent, module by module

Evidence log for workflow 3. Times are NZDT. Written while the work happens; nothing is back-filled unless marked.

## Setup

| Item | Value |
|---|---|
| Tool | Claude Code (CLI), model Claude Opus 5 (`claude-opus-5`), one agent. Sub-agents are forbidden by the group's W3 prompt |
| Operator | Rison |
| Branch | `w3-single-agent`, branched from `xiaofang2016/mealie` `mealie-next-backend-rewrite` at `99b22abc5`. The branch owner merges it back, so W3 does not commit to the shared branch directly. W5 used `w5-multi-agent` the same way |
| Working copy | Direct checkout at `/Users/rison/code/Waikato/574/mealie`. No worktree, no sparse checkout; the hold-out is enforced by the prompt only (see D2, D7) |
| Python oracle | Fork's own Python code, `PRODUCTION=False uv run python mealie/app.py`, host port 9000. Dev mode gives JWT secret `shh-secret-test-key` and the debug 422 body |
| Database | `compx574/mealie.db` copied to `dev/data/mealie.db` (sha `280e922ebba1a56a`, alembic `3527efeeec34`). The operator's previous local DB is kept at `dev/data/mealie.db.pre-w3.bak`; restore snapshot at `dev/data/mealie.w3-snapshot.db` |
| DB facts | 5 users. First by username is `bob`, `admin=0`. The only admin is `mikexiao`. So every `/api/admin*` request in `parity.hurl` is scored as a 401/403 |
| PHP sandbox | Image `mealie-w3-php` from `sandbox/Dockerfile` (PHP 8.4-cli, Composer 2, Hurl 8.0.1, socat). **Not built yet — Docker daemon was not running at setup time** |
| Framework | Laravel 13, PHP ^8.3 — same major as W1 and W5, so framework choice is not a variable between workflows |

## Workflow as specified by the group (transcribed from the group chat, Monday)

Two prompts per module, repeated for each module. Prompt A (contract only):

> 把 Mealie 的 FastAPI 后端（mealie/）用 PHP 重写到新目录 php-w3。Python 后端留在原地。使用相同的 /api 路径，以及已有的 SQLite 数据库 dev/data/mealie.db。不要对这个数据库运行 php artisan migrate。不要修改 frontend/app/lib/api/types/，也不要修改 en-US 以外的语言文件。不要打印密钥。Python 继续跑在 http://127.0.0.1:9000。这个 PHP 应用跑在 http://127.0.0.1:9001。只有本提示明确要求时才提交，提交说明以 workflow-3: 开头。
> 不要读取或复制 php-w1/。不要读取 compx574/workflow-1-single-instruction.md。不要启动子代理。
> 这是 workflow 3。只做一个模块，这一轮还不要写 PHP 行为。
> 模块：<模块名>
> 把这个模块追加到 compx574/workflow-3-contracts.md。每个接口都要写：方法和完整路径及其 Python 来源；JSON 字段名所用的 schema 文件；id 如何存储、如何返回；谁可以调用，依据是哪段 Python 权限检查；不实现什么。
> 不要把一个只返回固定错误的路由注册出来并当作完成。写完这一节契约后停止。

Gate: human checks that section of the contract.

Prompt B (implement that section only):

> <模块名> 的契约已通过。只实现 compx574/workflow-3-contracts.md 里的这一节。如果我改过某一行，按改过的版本做。
> 不要开始下一个模块。提交，说明以 workflow-3: 开头。然后停止。

Module order given by the group: app + validators + auth, then users, groups, households, recipe, organizers + unit_and_foods + comments, then admin + explore + parser + media + shared. `spa` is out of scope.

## Deviations from the group's W3 text, and why

| # | Change | Reason |
|---|---|---|
| D1 | **Phase 0 inside round 1**: before any route behaviour, build the Laravel app, the `mealie` DB connection, `App\Auth\Jwt::encode()/secret()`, auth middleware and id/date/error/JSON/pagination helpers | `compx574/hurl/run.sh` mints its token by calling `App\Auth\Jwt::encode(...)/::secret()` inside the candidate directory against a connection named `mealie`. Those three names are a hard contract and the group prompt never mentions them. Same reason as W5's D1 |
| D2 | **`compx574/hurl/` is held out from the implementation rounds.** Routes are written from the Python source only; self-verification is done by sending the same request to Python :9000 and to PHP :9001 and comparing status and body. `measure.sh` is run by the operator at round boundaries, not by the implementing agent | Adopted from W5's D3, and confirmed necessary by W5's reading of W1: `parity.hurl` asserts status codes only and its token belongs to a non-admin, so a build that can see the file can score high with status-only stubs. Holding it out keeps it an acceptance test. Without this, W3 would reproduce W1 and the three-way comparison would lose a point |
| D3 | **`measure.sh` scores a patched temp copy of `parity.hurl`** (bare `x=1` form bodies rewritten to `[FormParams]`), and checks Python answers 200 on `/api/users/self` before scoring | The shared `parity.hurl` does not parse under Hurl 8.0.1 and executes 0 requests. Copied from W5's `measure.sh` so the two workflows are scored by the same wrapper. The shared file is not edited |
| D4 | **Sandbox adapted for a macOS host**: `socat` added so `127.0.0.1:9000` inside the container reaches the Python oracle on the host | W5 ran inside the Linux dev container and shared its network namespace. `parity.hurl` hard-codes `127.0.0.1:9000`, so on macOS the port has to be forwarded into the container |
| D5 | **`/api/utils/download` added to round 1.** `mealie/routes/utility_routes.py` is a module, not a package, so the group's module list misses it | Same gap W5 found and assigned to its area 3 |
| D6 | **Floor score measured on the Phase 0 skeleton before any route code** | To report how much of W3's score comes from the skeleton alone. W5's floor on this DB was 12/265 |
| D2a | **Hold-out leak, disclosed.** During setup, before D2 was adopted, the agent counted `parity.hurl`'s candidate requests per module and listed the paths for rounds 1 and 4-7, and read its first ~80 lines (the `admin/about` and `admin/backups` entries). **Status assertions and jsonpath assertions were not read**, and the file is not open to the implementation rounds from here on. The known leak is therefore the path inventory, which is derivable from `mealie/routes/` anyway, plus the assertion shape of two admin endpoints | Recorded so the report does not claim a cleaner hold-out than W3 actually had |
| D7 | **Contamination disclosure.** Before starting, the operator asked the agent to read W5's *process* artifacts: `compx574/workflow-5/log.md`, `measure.sh`, `prompts/`, `results/`, `sandbox/`, and `workflow-5-split.md`. **Not read:** any file under `php-w5/`, `compx574/workflow-5-contracts/area-*.md`, `php-w1/`, `compx574/workflow-1-single-instruction.md` | The operator asked. W3's *process* (phase 0, hold-out, scoring wrapper, sandbox) is therefore derived from W5's and is not an independent result. W3's *API design* — every contract and every route — is written from the Python source only. The report must state this: W3 is an independent implementation, not an independent methodology |
| D7a | **Gate 1 delegated.** The operator answered the round 1 gate with "继续" rather than marking the contract, so the contract was taken as passing unchanged and round 1 proceeded. As in W5's D7, this changes what the gate measures for this round: agent-run, not human-run | Recorded rather than assumed. Later gates may differ; each is noted in the timeline |
| D8 | **Rounds re-cut from 7 to 9** using the real request counts in `parity.hurl` rather than the group's module list, because `households` (64 candidate requests) and `recipes` (44) are each larger than the other five modules combined | Keeps each gate reviewable. Module boundaries still follow `mealie/routes/` packages; only the grouping into rounds changed |

## Round plan

| Round | Modules | Candidate requests in `parity.hurl` |
|---|---|---|
| 1 | Phase 0 skeleton + `app`, `validators`, `auth`, `utility_routes` | 16 |
| 2 | `users` | 18 |
| 3 | `groups` | 27 |
| 4 | `households` core, `cookbooks`, `recipe-actions`, `webhooks`, `events`, `invitations` | 35 |
| 5 | `households/shopping`, `households/mealplans` | 29 |
| 6 | `recipe` CRUD, listing, `settings`, `last-made`, `duplicate`, `tag`, `suggestions`, timeline events | 20 |
| 7 | `recipe` image/assets/export/zip/url/scrape/ai | 24 |
| 8 | `organizers`, `unit_and_foods`, `comments` | 39 |
| 9 | `admin`, then `explore`, `media`, `parser`, `shared` | 64 |

## Reference points from the other workflows

Measured by `compx574/workflow-5/measure.sh` on this same shared DB (source: `compx574/workflow-5/log.md` and `results/`).

| Build | Parity (status asserts) | Parity without W1's contract middleware | Body accuracy (40 GETs) |
|---|---|---|---|
| Phase 0 skeleton, no routes | 12/265 | — | — |
| W5 multi-agent | 189/265 | — | 37/40 |
| W1 single instruction | 263/265 | 110/265 | 15/40 |

## Timeline

| Time | Phase | Event |
|---|---|---|
| 2026-10-10 ~15:54 | setup | Fetched `team/mealie-next-backend-rewrite`, checked out at `99b22abc5`. Read `run.sh`, `parity.hurl`, `accuracy_compare.py` |
| 2026-10-10 ~16:10 | setup | Operator asked for W5's approach to be read. W5 process artifacts read; `php-w5/` and `workflow-5-contracts/` not opened (D7) |
| 2026-10-10 ~16:20 | setup | Local `dev/data/mealie.db` (sha `10e3e232…`, first user `Zack`) backed up to `mealie.db.pre-w3.bak`; shared DB installed (sha `280e922e…`, first user `bob`). Verified the `parity.hurl` group id `dcf19c89-…` exists only in the shared DB. Snapshot `dev/data/mealie.w3-snapshot.db` taken |
| 2026-10-10 ~16:25 | setup | `sandbox/Dockerfile` written (W5's plus socat). **Build failed: Docker daemon not running.** Image still to be built |
| 2026-10-10 ~16:40 | R1 | Round 1 contract written to `compx574/workflow-3-contracts.md` (16 endpoints + the harness contract), from Python source only. Findings worth carrying: `ValidationResponse` and `MealieAuthToken` are plain `BaseModel`s, so their keys stay snake_case while the `about` schemas camelCase; `/api/validators/household` raises `Exception("group_id not set")` in `repository_household.py:69` on every call and is always a 500; the OIDC guards return 500 on `/oauth` and `/oauth/callback` but 404 on the two `native` routes. No PHP written. Stopped for the gate |
| 2026-10-10 ~21:20 | setup | Docker started. Port 9000 was held by the VS Code dev container (`sleepy_napier`), which bind-mounts this repo at `/workspaces/mealie`, so the oracle was started inside it — the same arrangement W5 used. `PRODUCTION=False uv run python mealie/app.py`, `/api/app/about` 200 |
| 2026-10-10 ~21:22 | setup | Sandbox image `mealie-w3-php` built: PHP 8.4.26, Composer 2.10.3, Hurl 8.0.1 — identical versions to W5's image. `sandbox/score.sh` and `sandbox/compare.sh` added, both running everything inside one container with socat forwarding `127.0.0.1:9000` to the host |
| 2026-10-10 ~21:27 | R1/P0 | `composer create-project laravel/laravel:^13 php-w3` → Laravel 13.17. The scaffold migrated **its own** `php-w3/database/database.sqlite`; `dev/data/mealie.db` sha unchanged. Deleted the scaffold's `CLAUDE.md`/`AGENTS.md` (Laravel Boost text instructing agents to run a setup routine) |
| 2026-10-10 ~21:30 | R1/P0 | Skeleton written: `config/mealie.php`, the `mealie` connection, `App\Auth\Jwt`, `App\Support\{Guid,Errors,Slug,CurrentUser}`, `MealieAuth` middleware, `routes/api.php` loader, and FastAPI-shaped 401/403/404/405/422/500 renderers in `bootstrap/app.php`. The renderers register the specific HTTP exceptions **before** the catch-all `Throwable` — the ordering W5 found the hard way, where a catch-all placed first turns every 401/403 into a 500 |
| 2026-10-10 ~21:31 | R1/P0 | Harness contract verified end to end: a token minted by `php-w3`'s `Jwt::encode()/secret()` is accepted by Python `/api/users/self` (200, user `bob`) |
| 2026-10-10 ~21:31 | R1/P0 | **Floor score 12/265** (R1 2/33, R2 2/83, R3 7/73, R4 1/76) — `results/p0-floor.txt`. Identical to W5's floor on this DB, which is the expected result for two skeletons with no routes and confirms the two scoring wrappers agree |
| 2026-10-10 ~21:33 | R1 | 16 routes written from the contract. Self-check (`sandbox/compare.sh`, 25 requests against both backends, `compx574/hurl/` not read or run): **22 identical, 3 differing**. All three differences are the free-text `message` of a 422 — status and the three body keys match; Python's text appends the oracle's own absolute source path (`/workspaces/mealie/...`), which is not reproducible and not attempted. One was tightened anyway: an absent body now reports `missing` at `('body',)` rather than a shape error |
| 2026-10-10 ~21:34 | R1 | **After round 1: 27/265** (R1 16/33, R2 2/83, R3 8/73, R4 1/76) — `results/p1-after-round1.txt`. All 16 round 1 endpoints PASS. `dev/data/mealie.db` sha `280e922e…` unchanged by the run, so no handler wrote to the shared database |

| 2026-10-11 ~00:50 | Gate 1 | Operator answered "继续" again, so gate 1 passed unchanged and round 2's contract was written. Same delegation as D7a |
| 2026-10-11 ~00:55 | R2 | Round 2 contract written: 17 endpoints from `mealie/routes/users/`. Findings: the mount prefixes are **not** uniform — `api_tokens.py` and `crud.py` carry `/users` themselves while `registration.py`, `forgot_password.py`, `images.py` and `ratings.py` are included with it, so the prefix has to be worked out per file; `users.auth_method` stores the enum **name** (`MEALIE`) but serializes the **value** (`Mealie`); `/users/self/ratings` returns a 3-key summary while `/users/{id}/ratings` returns a 5-key `UserRatingOut`; `assert_user_change_allowed` is called with the caller as both arguments in four handlers, which disables every check except the identity one; `DELETE /users/api-tokens/{token_id}` takes an **integer** id and answers 404 with a bare string detail rather than an ErrorResponse object. Seven endpoints write to the database and are listed so the self-check exercises only their rejection paths. No PHP written |

| 2026-10-11 ~01:05 | Gate 2 | Operator answered "继续", so gate 2 passed unchanged — agent-run, as D7a. The operator also asked how closely the gate should be reviewed and was told what a skim can and cannot catch; no change to this round |
| 2026-10-11 ~01:20 | R2 | 17 routes written. Three contract entries were corrected against the oracle **before** implementing: `POST /users/register` is a 422 from the `group_or_token` field validator before the handler's 403 can run, unless `group` is non-empty; `forgot-password` answers 200 with a body of literally `null` for an unknown address; `reset-password` answers 400 `{"detail":"Invalid token"}` with a bare string detail |
| 2026-10-11 ~01:25 | R2 | Self-check (`sandbox/compare.sh`, 31 requests, bodies included — the script gained JSON-body support this round): **19 identical, 12 differing**. Every status matches; all 12 differences are the free-text `message` of a 422, where Python appends the oracle's own source path and pydantic's `ctx`, and reports every error in a body while this implementation reports the first. `GET /users/self` matches byte for byte, which exercises the camelCase mapping, the `MEALIE → Mealie` enum conversion, the group/household joins and the token list. One real defect was found and fixed: Laravel renders `JsonResponse(null)` as `{}`, so `forgot-password` needed an explicit `null` body |
| 2026-10-11 ~01:30 | R2 | **After round 2: 44/265** (R1 **33/33**, R2 2/83, R3 8/73, R4 1/76) — `results/p2-after-round2.txt`. All 18 `/api/users` requests PASS. R1 is now complete; W5 finished that region at 28/33. `dev/data/mealie.db` sha unchanged, so none of the round's seven writing endpoints was reached |

| 2026-10-11 ~01:40 | Gate 3 | Operator answered "继续"; gate 3 passed unchanged, agent-run as D7a |
| 2026-10-11 ~01:55 | R3 | Round 3 contract written: 27 endpoints from `mealie/routes/groups/`. Findings: authentication here comes from the **controller class** (`BaseUserController`), not the router type — four files use a plain `APIRouter` and are still authenticated; the pagination envelope is snake_case (`per_page`, `total_pages`) while its `items` are camelCase, in the same response; `next`/`previous` are built from `url_path_for` on the sub-router so they carry **no `/api` prefix**, and unset parameters appear as the literal string `None`; `bob`'s five permission bits are all 0, so the nine `can_manage`-guarded endpoints are 403 for the parity token; `DELETE /groups/reports/{id}` answers **500** for an id that `GET` on the same path answers 404 for, because the handler catches every exception. No PHP written |

| 2026-10-11 ~02:10 | Gate 3 | Operator answered "继续"; gate 3 passed unchanged, agent-run as D7a |
| 2026-10-11 ~02:40 | R3 | 27 routes written, plus `App\\Support\\Pagination` (the envelope, the `page_all` semantics of `repository_generic.py:321-412`, and the guide URLs) which rounds 4-9 reuse. Self-check: first pass **20 identical, 15 differing** with four real defects, all fixed: (a) PHP escapes `/` and non-ASCII in JSON while FastAPI does neither, which broke every pagination guide — fixed once as response middleware rather than at each call site; (b) `GET /groups/storage` sums `<data>/recipes/<id>` over the group's recipes, **not** a `groups/` directory, and `get_dir_size` counts the directory entry itself; (c) a route that found nothing through `HttpRepo.get_one` returns an `ErrorResponse` object under `detail`, not the bare `"Not Found"` string an unrouted path gets — three different 404 shapes in this one module; (d) body validation precedes the in-handler permission check. Second pass: **26 identical, 9 differing**, all 9 only the free-text `message` of a 422 |
| 2026-10-11 ~02:50 | R3 | First score **69/265** with two failures, both the same root cause as (d) but for **path** parameters and for a scalar body: `parity.hurl` sends an all-zero UUID, which is not a valid v4, and a body of `0`. The self-check had missed them because its request list used a valid-v4 zero UUID. Generalized to "all request validation precedes every permission check", applied to 12 handlers across rounds 1-3, and promoted to the contract's conventions for the later rounds |
| 2026-10-11 ~02:55 | R3 | **After round 3: 71/265** (R1 33/33, R2 2/83, R3 8/73, R4 28/76) — `results/p3-after-round3.txt`. All 27 `/api/groups` requests PASS, R1 still complete, no regression in rounds 1-2. `dev/data/mealie.db` sha unchanged |

## Results so far

| Label | Score | R1 | R2 | R3 | R4 |
|---|---|---|---|---|---|
| `p0-floor` — skeleton, no routes | 12/265 | 2/33 | 2/83 | 7/73 | 1/76 |
| `p1-after-round1` — app, auth, validators, utils | 27/265 | 16/33 | 2/83 | 8/73 | 1/76 |
| `p2-after-round2` — users | 44/265 | 33/33 | 2/83 | 8/73 | 1/76 |
| `p3-after-round3` — groups | 71/265 | 33/33 | 2/83 | 8/73 | 28/76 |

Region labels are W5's four areas, kept unchanged in `measure.sh` so the two workflows' breakdowns
line up. They do not correspond to W3's rounds.
