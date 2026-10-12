# Workflow 3 — single agent, module by module

Section for the group report. Written by the W3 operator; hand to whoever merges the four sections.
Everything below is measured from this repository unless it says otherwise.

## 1. What the workflow was

One Claude Code agent, no sub-agents, working through `mealie/routes/` in nine rounds. Each round
had two prompts:

1. **Contract only.** Write a section of `compx574/workflow-3-contracts.md` for that module — every
   endpoint's method and full path, its Python source line, the schema its JSON keys come from, how
   ids are stored and returned, which Python check gates it, and what will deliberately not be
   implemented. Then stop.
2. **Implement that section only.** No other module. Commit. Stop.

The operator reviewed between the two. Nine rounds, so nine review points.

## 2. Tool, model, cost, time, human effort

| Item | Value |
|---|---|
| Tool | Claude Code (CLI), one main-loop agent; sub-agents forbidden by the prompt |
| Model | Claude Opus 5 (`claude-opus-5`) |
| Human turns | ~20 total: ~11 in setup and method design, then **9 approvals**, one per gate |
| Human review depth | The operator approved each gate by replying "continue" without marking the contract. The log records the gates as **agent-run**, the same as W5 |
| Elapsed | Nine round-commits spanning roughly 20 hours, but that includes long idle periods waiting for the operator. Agent working time per round is better read from the rounds where the operator replied immediately: **rounds 8 and 9 took 17 and 14 minutes** end to end, including contract, implementation, self-check and scoring. Mid-size rounds ran 78–93 minutes |
| Timestamp caveat | Commits were made from two machines (host and dev container) in different timezones, so the raw `git log` times are not a clean wall clock |
| Token cost | **Not instrumented.** W5 could report per-agent token counts because Claude Code reports usage for sub-agents; a single main-loop session has no equivalent readout the agent can access. If a number is needed, the operator can read it from Claude Code's `/usage`. This is a gap in W3's evidence and should be stated as one rather than estimated |

## 3. Results

Scored with `compx574/workflow-3/measure.sh`, which is `compx574/workflow-5/measure.sh` with only
the names changed, so W3 and W5 are scored identically.

| Build | Parity (status asserts) | Body accuracy |
|---|---|---|
| Phase 0 skeleton, no routes | 12/265 | — |
| **W3** | **265/265** | **43/43** |
| W5 | 189/265 | 37/40 |
| W1 | 263/265 | 15/40 |

Per round: 12 → 27 → 44 → 71 → 103 → 132 → 152 → 172 → 211 → 265.

W1's and W5's figures are read from `compx574/workflow-5/results/`. The body-accuracy denominators
differ (W5's script sampled 40 paths, the shared script 43), so those two columns are not
comparable row to row.

## 4. What the parity check actually measures — the limit the report needs

This is the most important thing W3 can contribute to the comparison, and it is measured, not
argued.

**Of the 265 scored requests, the Python oracle answers 2xx on only 75.** The other 190 are
rejection paths: 133 are 422, 38 are 403, 12 are 404, 4 are 500, one each 400/401/405.

Broken down by method:

| Method | Requests | Reach a 2xx | Rejection only |
|---|---|---|---|
| GET | 121 | 73 | 48 |
| POST | 76 | 2 | 74 |
| PUT | 36 | 0 | 36 |
| DELETE | 29 | 0 | 29 |
| PATCH | 3 | 0 | 3 |
| **Total** | **265** | **75** | **190** |

**144 requests (54%) are writes, and exactly 2 of them reach a success path.** That is by design —
`parity.hurl`'s own header says the other methods "send a rejected body or a zero id, so the shared
database is left as it is". The consequence is that **the check cannot distinguish a backend that
implements writes from one that does not**, and all three workflows are scored under that blindness.

Concretely for W3: about half the write paths are not implemented and answer 501.
`compx574/workflow-3-contracts.md` lists every one under "Not implemented" — scraping and import,
images and assets, archive handling, seeders, migrators, email, the event bus, and the service-layer
writes behind shopping lists and recipes.

**And 38 of W3's passes are `/api/admin/*`, a package with no behaviour implemented at all.** The
token `run.sh` mints belongs to `bob`, who is not an admin, so `get_admin_user` refuses every one
before the handler runs. For a real admin caller this PHP would be wrong. None of that is visible in
the score.

## 5. Code quality — where W3's output is weak

The meeting raised that some generated backends put business logic in controllers and have no
service layer. **W3's output has this problem.**

| Measure | Value |
|---|---|
| Hand-written PHP | 69 files, 7,757 lines (excluding `vendor/` and Laravel scaffolding) |
| Layers present | `app/Http/Controllers/` (38 files), `app/Support/` (13 files), `app/Auth/` |
| Layers absent | **no `app/Services/`, no `app/Repositories/`** |
| Direct `DB::connection(...)` calls inside controllers | **100** |

Mealie's Python is explicitly repository → service → controller (`CLAUDE.md` states the pattern, and
`mealie/repos/`, `mealie/services/` implement it). W3 reproduced the **HTTP surface** of that
architecture and not the architecture itself: controllers query SQLite directly, and the only shared
layer is a set of serializers and helpers in `app/Support/`.

Why this happened, as far as the operator can tell: the per-round contract specified
*endpoint behaviour* — paths, status codes, JSON shapes, permission gates — and never specified
*internal structure*. The agent optimised for what the contract asked for and what the parity check
rewards, and neither of those looks at layering. A contract line like "repositories must not be
reached from a controller" would likely have changed the outcome, and that is a cheap, testable
improvement to the method rather than a model limitation.

**A reflexive note worth putting in the report.** The group's earlier code review of Mealie listed
"the service layer is advisory, not enforced" as one of its candidate issues — Python's own
controllers can and sometimes do reach past the service layer. The AI rewrite did not merely fail to
copy the layering; it reproduced, in a stronger form, the exact weakness the review had identified
in the original. An agent working from observable HTTP behaviour inherits a codebase's structural
discipline only to the extent that the discipline is observable, and this one is not.

Where the design did hold up: shapes were factored once and reused. Round 5 built
`ShoppingSerializer::unit/food` for shopping items, and rounds 6 and 8 reused them unchanged for
recipe ingredients and for `/api/foods` and `/api/units`. `Pagination` was written in round 3 and
used by every later round.

## 6. What the contract-first method actually bought

Measured from the log, the contract caught errors **before** code was written in several rounds —
the agent verified its own claims against the running Python oracle while writing the contract, and
corrected the contract three times in round 2 alone (registration validator precedence, the
`forgot-password` null body, the `reset-password` bare-string detail).

Rounds where the implementation then needed correction anyway, found by the self-check:

| Round | Defects found before scoring |
|---|---|
| 3 | 4 — JSON slash/unicode escaping, storage source, two 404 shapes, permission ordering |
| 4 | 2 — `PUT`/`DELETE` on a cookbook id have three distinct failure modes not visible from the signature |
| 5 | 1 — `JsonResponse(null)` renders `{}` |
| 6 | 1 — `recipeYield` coerced to `""` instead of `null` |
| 8 | 1 — sibling controllers differ: `categories/slug` 404 vs `tags/slug` 500 |
| 9 | 4 — media handlers never touch the database |

The pattern worth reporting: **contracts written from source alone were wrong often enough to
matter, and were only made right by measuring against the running oracle.** Reading the Python was
necessary and not sufficient.

## 7. Test-method notes other workflows should know

- **Hold-out.** `compx574/hurl/` was never read or run by the implementing work. Each round verified
  itself by sending the same request to Python :9000 and PHP :9001 and diffing
  (`sandbox/compare.sh`); `measure.sh` ran only at round boundaries. One leak is recorded as D2a:
  during setup, before the hold-out was adopted, the path inventory was counted and the first ~80
  lines read. Status assertions were not.
- **A self-check can corrupt the shared database.** In round 5 it did: `ShoppingListCreate` and
  `PlanRulesCreate` default every field, so `POST` with `{}` returns 201 and inserts a row. One
  shopping list and one meal-plan rule were created before the post-run checksum comparison caught
  it; restored from `dev/data/mealie.w3-snapshot.db` with the oracle stopped. **Rule for anyone else
  probing: a probe body is only safe if the schema has a required field.**
- **`sandbox/compare.sh` compares only the first 400 bytes of a body.** It missed the round 6
  `recipeYield` defect, which a field-by-field diff found. Its "identical" count is not byte
  equality for long responses.
- **Final integrity**: `dev/data/mealie.db` is byte-identical to `compx574/mealie.db`, and no shared
  file under `compx574/` was modified.

## 8. One claim the group still needs to verify

The report will likely argue that W1's 263/265 comes from tuning against the acceptance test.
**W3 did not measure that and cannot support it.** `php-w1` was held out for the whole run.

What W3 *did* measure, in Python: `GET /api/admin/nonexistent` with a non-admin token returns
**404**, not 403, because routing happens before dependency resolution. W3 reproduces that — every
route is declared from the Python source, so an undeclared admin path 404s.

`compx574/workflow-5/log.md` records that W1 prepends `MatchPythonContract` as global middleware so
that every `/api/admin*` path returns 401/403 "whether or not a route exists", that 153 of its 263
passes depend on it, and that removing it drops W1 to 110/265. **Someone should run that one probe
against `php-w1` directly** so the claim rests on a measurement rather than a quotation.

## 9. Contribution record

All mini-project contributions by this member, per the brief: every PR regardless of merge status,
with a link, a short description, completion status, and the issue or reason behind it.

### 9.1 Upstream contributions to `mealie-recipes/mealie`

| # | Type | Title | Status | Link |
|---|---|---|---|---|
| 8306 | Issue | Deleting a food used only as a recipe substitution leaves orphaned/broken references with no warning | **Fixed and closed 2026-09-06** by maintainer PR [#8311](https://github.com/mealie-recipes/mealie/pull/8311) | [issues/8306](https://github.com/mealie-recipes/mealie/issues/8306) |
| 8308 | Issue | A single food with a NULL description crashes the entire foods list endpoint (500), silently shown as "no data" | **Fixed and closed 2026-09-06** by PR [#8309](https://github.com/mealie-recipes/mealie/pull/8309) | [issues/8308](https://github.com/mealie-recipes/mealie/issues/8308) |
| 8111 | PR | fix: prevent duplicate iOS camera photos from overwriting recipe step assets | **Merged 2026-09-16** — 3 commits, +52/−2, 2 files | [pull/8111](https://github.com/mealie-recipes/mealie/pull/8111) |
| 8487 | PR | fix: shopping list item order reverting after drag-and-drop reorder | **Open** since 2026-09-21 — 1 commit, +16/−0, 2 files | [pull/8487](https://github.com/mealie-recipes/mealie/pull/8487) |

- **#8111** came out of using the app on iOS: the camera returns the same filename for successive
  shots, so a second photo overwrote the first on a recipe step. Merged upstream.
- **#8487** is a frontend state bug — after a drag-and-drop reorder the shopping list snaps back to
  its previous order. Still awaiting maintainer review; no changes requested so far.
- **#8306 and #8308** are both data-integrity bugs in `ingredient_foods`, found while working in
  that area. Neither was fixed by this member: both were filed, triaged and **fixed by other
  people's merged PRs on the same day** — #8306 by maintainer `michael-genson` in
  [#8311](https://github.com/mealie-recipes/mealie/pull/8311) ("Thanks for the report! This will be
  fixed by…"), #8308 by contributor `chiliec` in
  [#8309](https://github.com/mealie-recipes/mealie/pull/8309). The contribution here is the
  diagnosis and the reproduction, not the patch, and the report should say so plainly — a
  same-day maintainer fix is a reasonable signal that the reports were clear and actionable.

### 9.2 Code review deliverable

Group code review of Mealie (submitted Sep 2026). This member produced the evidence dossier that
the group's report was written from — seven candidate issues verified against commit `56fbf6ad0`,
five positive design choices, a prioritisation, and two commonly-made criticisms checked and
**disproved** ("the frontend is untested" — 34 test files; "CI is weak" — sqlite + postgres matrix,
live LDAP, Ruff, mypy, CodeQL, Trivy, Playwright e2e).

Files: `code-review-evidence.md`, `Mealie-Code-Review-Report-DRAFT.docx` (held outside the repo).

### 9.3 Workflow 3 — this section's subject

| Item | Value |
|---|---|
| PR | https://github.com/xiaofang2016/mealie/pull/2 |
| Title | `workflow-3: PHP rewrite of the Mealie API, single agent module by module` |
| Branch | `RisonG:w3-single-agent` → `xiaofang2016:mealie-next-backend-rewrite` |
| Commits | 10 |
| Diff | +41,065 / −0 across 162 files |
| Status | **Merged** |
| Reason / issue | Mini-project workflow comparison; no upstream issue — course work on a fork, not intended for upstream Mealie |
| Deliverables | `php-w3/` (the rewrite); `compx574/workflow-3-contracts.md` (nine contracts, ~1,500 lines); `compx574/workflow-3/log.md` (setup, nine deviations with reasons, per-round timeline, final comparison); `compx574/workflow-3/{measure.sh, accuracy_compare_w3.py, sandbox/, results/}` (scoring, derived from W5's so the numbers line up) |

Also produced, inside that PR rather than as a separate one:
`compx574/workflow-3/sandbox/{Dockerfile, score.sh, compare.sh, token.sh, restore-db.sh}` — the
harness. `restore-db.sh` and the "a probe body is only safe if the schema has a required field" rule
came out of the round 5 incident and are reusable by the other workflows.
