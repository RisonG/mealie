# Individual contribution report — COMPX574 mini-project

**Name:** _[fill in]_  **Student ID:** _[fill in]_  **Email:** _[fill in]_
**GitHub:** [@RisonG](https://github.com/RisonG)
**Project:** [mealie-recipes/mealie](https://github.com/mealie-recipes/mealie)

## Summary

| # | Contribution | Type | Status |
|---|---|---|---|
| 1 | Deleting a food used only as a recipe substitution leaves orphaned references | Issue [#8306](https://github.com/mealie-recipes/mealie/issues/8306) | Accepted, fixed by maintainer same day |
| 2 | A single food with a NULL description crashes the whole foods list endpoint | Issue [#8308](https://github.com/mealie-recipes/mealie/issues/8308) | Accepted, fixed same day |
| 3 | Duplicate iOS camera photos overwrite recipe step assets | PR [#8111](https://github.com/mealie-recipes/mealie/pull/8111) | **Merged** 2026-09-16 |
| 4 | Shopping list order reverts after drag-and-drop reorder | PR [#8487](https://github.com/mealie-recipes/mealie/pull/8487) | Open, awaiting review |
| 5 | Group code review — evidence dossier | Report | Submitted Sep 2026 |
| 6 | Workflow 3: PHP rewrite of the Mealie API with a single AI agent | PR [xiaofang2016/mealie#2](https://github.com/xiaofang2016/mealie/pull/2) | **Merged** |

Two bug reports accepted and fixed by maintainers, one fix merged upstream, one fix awaiting
review, one written deliverable, and one AI-workflow experiment.

## 1. Upstream contributions to Mealie

### 1.1 PR #8111 — duplicate iOS camera photos overwrite recipe step assets (merged)

[pull/8111](https://github.com/mealie-recipes/mealie/pull/8111) · merged 2026-09-16 · 3 commits,
+52/−2, 2 files

**Found by using the app.** Taking several photos with the in-app camera on iOS to attach to a
recipe step kept only the first one; every later photo's link pointed at the same file.

**Cause.** iOS Safari names every camera capture `image.jpg`, no matter how many are taken in a
session — a photo picked from the library keeps its own filename, which is why that path never
showed the bug. `POST /api/recipes/{slug}/assets` derives the on-disk filename from the
client-supplied `name` through `slugify()` with no uniqueness check, and opens the destination with
`"wb"`. So a second upload with the same name silently overwrote the first file on disk *and*
inserted a second `RecipeAsset` row pointing at the same URL.

**Fix.** In `upload_recipe_asset`, check whether the destination exists before writing and append a
short uuid4 suffix if it does, so the second asset gets its own path. Added an integration test
that uploads twice under the same name and asserts both files survive.

Files: `mealie/routes/recipe/recipe_crud_routes.py` (+9/−2),
`tests/integration_tests/user_recipe_tests/test_recipe_image_assets.py` (+43).

### 1.2 PR #8487 — shopping list order reverts after reorder (open)

[pull/8487](https://github.com/mealie-recipes/mealie/pull/8487) · opened 2026-09-21 · 1 commit,
+16/−0, 2 files · fixes existing issue
[#5010](https://github.com/mealie-recipes/mealie/issues/5010) (reported by another user)

**Symptom.** Drag a shopping list item to a new position; the new order shows for a moment, then
snaps back.

**Two causes, both needed fixing.**

1. `updateUncheckedListItems()` did not bump each item's `updatedAt` before queueing the update,
   where `saveListItem()` does. The client-side merge that reconciles a GET response against still
   pending local changes compares timestamps, so it could not tell the reordered item was newer
   than the server's copy and sometimes discarded the local reorder. Drag reorder, check-all and
   uncheck-all all go through this path.
2. The page polls the server every five seconds. An earlier GET that resolved *after* a newer
   PUT/GET pair — ordinary out-of-order network responses — could overwrite the UI with stale data
   even though the reorder had already saved. `refresh()` now tags each call with an incrementing
   generation number and discards its own result if a newer refresh has started since.

Files: `frontend/app/composables/shopping-list-page/sub-composables/use-shopping-list-crud.ts`
(+3), `use-shopping-list-data.ts` (+13).

**Status:** open since 2026-09-21, no changes requested.

### 1.3 Issues #8306 and #8308 — data integrity in `ingredient_foods`

Both found while working in the foods area, both filed 2026-09-06, both triaged and fixed the same
day — **by other people**, which is the honest framing: the contribution here is the diagnosis and
a reproduction clear enough to act on, not the patch.

| Issue | What | Fixed by |
|---|---|---|
| [#8306](https://github.com/mealie-recipes/mealie/issues/8306) | Deleting a food that is used only as a recipe *substitution* leaves orphaned or broken references, with no warning to the user | Maintainer `michael-genson`, PR [#8311](https://github.com/mealie-recipes/mealie/pull/8311) — "Thanks for the report! This will be fixed by…" |
| [#8308](https://github.com/mealie-recipes/mealie/issues/8308) | One food row with a NULL `description` makes the entire foods list endpoint return 500; the frontend shows it silently as "no data", so the cause is invisible | Contributor `chiliec`, PR [#8309](https://github.com/mealie-recipes/mealie/pull/8309) |

Both fixing PRs were merged on 2026-09-06.

## 2. Group code review — evidence dossier

For the group's code review of Mealie (submitted Sep 2026), I produced the evidence dossier the
report was written from, verified against commit `56fbf6ad0`:

- **Seven candidate issues**, each with named files and a concrete consequence, for the group to cut
  to five — including client-invented sync semantics with no server-side concurrency control, no
  frontend type-checking in the toolchain, generated code treated as source of truth with nothing
  verifying regeneration, no per-test isolation, an advisory-only service layer, 51 migrations with
  only a static ordering test, and the groups/households permission model the maintainers had
  already flagged.
- **Five positive design choices** to cut to three.
- **A prioritisation** for one release cycle, by impact against cost and maintainer capacity.
- **Two commonly-made criticisms checked and disproved**, so nobody wrote them: "the frontend is
  untested" (34 test files) and "CI is weak" (sqlite + postgres matrix against a real Postgres
  service container, a live LDAP container, Ruff, mypy, CodeQL, Trivy, Playwright e2e, and
  Conventional-Commits title linting).

Files: `code-review-evidence.md`, `Mealie-Code-Review-Report-DRAFT.docx`.

## 3. Workflow 3 — PHP rewrite with a single AI agent

My part of the group's AI-workflow comparison. The other two workflows (single instruction,
multi-agent) were done by teammates; the cross-workflow analysis is in the group report. What
follows is my own run.

**Method.** One Claude Code agent, no sub-agents, working through `mealie/routes/` in nine rounds.
Each round: write a contract for one module first — every endpoint's method and full path, its
Python source line, the schema its JSON keys come from, how ids are stored and returned, which
Python check gates it, and what will deliberately not be implemented — then stop for review, then
implement only that contract, then score.

**Model, cost and effort** — read from the Claude Code session transcript, not estimated:

| Item | Value |
|---|---|
| Model | `claude-opus-5`, all 843 assistant turns, no fallback |
| Tool calls | 366 |
| **Active agent time** | **≈2.6 hours** |
| Wall clock | 48.9 hours, of which 46.3 were the agent idle waiting for me |
| My own turns | ~20: about 11 setting up and designing the method, then 9 approvals |
| Tokens | 962k output · 10.5M cache write · 320M cache read |

No dollar figure: that depends on the subscription rather than on the counts, and `/usage` is the
authoritative source.

**Result.** 265/265 on the shared parity check and 43/43 on body accuracy.

**What that score does and does not mean.** Of the 265 scored requests the Python oracle answers
2xx on only 75; the rest are rejection paths. By method, 144 requests are writes and exactly 2 of
them reach a success path, because the shared check deliberately sends rejectable bodies and zero
ids so the shared database is left alone. So the check largely measures whether a route exists and
validates its input the way FastAPI does — not whether it does the work behind it. Concretely:
38 of my passes are `/api/admin/*`, a package where I implemented no behaviour at all, and about
half the write paths return 501 by design. Every one is listed under "Not implemented" in the
contract file.

**What I'd do differently.** The contracts specified endpoint behaviour and never internal
structure, so the output has 38 controllers with 100 direct database calls and no service or
repository layer — the same weakness our own code review had flagged in Mealie's Python. One line
in the contract template ("a controller must not reach the database directly") would likely have
fixed it. That is a cheap change to the method, not a limit of the model.

**Deliverables** (PR [xiaofang2016/mealie#2](https://github.com/xiaofang2016/mealie/pull/2),
merged, 10 commits, +41,065 across 162 files):

- `php-w3/` — the rewrite, Laravel 13 on PHP 8.4
- `compx574/workflow-3-contracts.md` — nine contracts, ~1,500 lines
- `compx574/workflow-3/log.md` — setup, nine deviations with reasons, per-round timeline
- `compx574/workflow-3/{measure.sh, accuracy_compare_w3.py, sandbox/, results/}` — scoring harness,
  derived from the multi-agent workflow's so the numbers are comparable
- `compx574/workflow-3/report-w3.md` — my section of the group report

One process note worth recording: in round 5 my own self-check wrote two rows into the shared
database, because two schemas default every field and so accept an empty object. A post-run
checksum comparison caught it and the database was restored from a snapshot; I added
`sandbox/restore-db.sh` and the rule that a probe body is only safe if the schema has a required
field, which the other workflows can reuse.
