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
