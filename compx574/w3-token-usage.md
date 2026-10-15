# W3 token usage

Workflow 3 — single agent, module by module. Counterpart to `w1-token-usage.md`.

**Model:** `claude-opus-5` for all 900 assistant turns. No fallback, no model switch.
**Tool:** Claude Code (CLI), one main-loop agent. Sub-agents were forbidden by the prompt, so there
are no delegated sessions and all usage is in one place.

## Totals

| | Tokens |
|---|---:|
| Output | 1,047,991 |
| Cache write | 12,808,917 |
| Cache read | 361,217,448 |
| Fresh input | 1,800 |
| **Sum of all four** | **375,076,156** |

| | |
|---|---|
| Assistant turns | 900 |
| Tool calls | 388 |
| Wall clock | 127.9 h |
| **Active agent time** | **2.8 h** |
| Idle, waiting on the operator | 125.1 h |

**Read the three token columns separately, not as one number.** Cache read is ~96% of the total and
is the cheapest of the four — it is the conversation being re-read on each turn at cache rates
rather than re-sent at input rates. The figure that tracks work produced is **output: 1.05M**.
A single long session inflates cache read in a way that a set of short sessions does not, which
matters when comparing workflows (see "Comparability" below).

## By round

A round is one contract + one implementation + self-check + scoring, bounded by its commit.

| Segment | Turns | Tools | Output | Cache write | Cache read | Active min |
|---|---:|---:|---:|---:|---:|---:|
| Round 1 — skeleton, app/auth/validators/utils | 254 | 106 | 257,349 | 1,204,440 | 37,597,672 | 44 |
| Round 2 — users | 66 | 31 | 85,568 | 742,143 | 17,059,713 | 11 |
| Round 3 — groups | 90 | 43 | 90,889 | 1,853,564 | 28,772,370 | 12 |
| Round 4 — households core | 60 | 30 | 72,244 | 102,123 | 24,667,098 | 8 |
| Round 5 — shopping lists, meal plans | 93 | 44 | 93,446 | 1,438,935 | 42,494,495 | 15 |
| Round 6 — recipe CRUD, timeline, bulk actions | 48 | 23 | 54,447 | 68,385 | 25,310,063 | 7 |
| Round 7 — recipe import, media, exports, shared | 50 | 22 | 65,062 | 69,562 | 28,472,313 | 7 |
| Round 8 — organizers, foods, units, comments | 39 | 16 | 49,997 | 70,013 | 23,515,974 | 16 |
| Round 9 — admin, explore, media, parser, shared | 68 | 27 | 84,021 | 119,005 | 44,220,614 | 14 |
| After round 9 — report sections, docx, this file | 132 | 46 | 194,968 | 7,140,747 | 89,107,136 | 33 |
| **Total** | **900** | **388** | **1,047,991** | **12,808,917** | **361,217,448** | **168** |

Three things the table shows:

- **Round 1 is 25% of output and 26% of active time on its own.** It carries everything that was
  not repeated later: reading `run.sh` and the scoring harness, building the Laravel skeleton,
  deriving the JWT contract, writing the sandbox and the compare/score scripts, and measuring the
  floor score. The nine rounds are not comparable units of work.
- **Rounds get cheaper as the contracts accumulate.** Rounds 6–9 average 54k output and 11 active
  minutes against 93k and 20 minutes for rounds 2–5, while covering more endpoints — 21, 24, 41 and
  64 against 18, 27, 35 and 30. The serializers and helpers written in rounds 3–5 (`Pagination`,
  `ShoppingSerializer`, `IngredientDisplay`) were reused rather than rewritten.
- **Cache read does not fall with them**, because it scales with conversation length, not with work
  done. Round 9 reads 44M for 14 active minutes. This is the clearest reason not to treat the
  grand total as a cost proxy.

## Cost

**No dollar figure is given here.** The counts above are exact; converting them depends on the
subscription the work ran under rather than on published per-token rates, and Claude Code's
`/usage` is the authoritative source if the report needs a number. Quoting a price derived from the
counts alone would be a guess presented as a measurement.

If a rate card is applied later, the three input columns are priced differently — cache read is
roughly a tenth of base input, cache write roughly a quarter above it — so they must be multiplied
separately, not summed first.

## Comparability with W1 and W5

Three cautions before these numbers go into a shared table:

1. **W5 reports per-sub-agent totals** (1,271,413 tokens for its four resumed agents, per
   `compx574/workflow-5/log.md`). Those are four short delegated runs whose usage was reported back
   by the `Agent` tool. W3's figure is one 900-turn session that also contains the environment
   setup, the method design, the scoring harness, both report sections and this file. They are not
   the same unit.
2. **W5's first sub-agent run was lost to an HTTP 429** and its usage was never reported, so W5's
   number is a floor, not a total.
3. **Cache read punishes long single sessions.** W3's 361M is an artefact of one continuous
   conversation; the same work split across ten fresh sessions would show a fraction of it with
   identical output. Compare the **output** column across workflows, and treat cache read as
   evidence about session shape rather than about effort.

## How these numbers were produced

Claude Code writes one JSON object per line to
`~/.claude/projects/<project-slug>/<session-id>.jsonl`, and every assistant line carries a `usage`
block. `compx574/workflow-3/extract-usage.py` reads that file, segments it by the round commits,
and sums the four token fields per segment. Active time is the sum of gaps between consecutive
events of ten minutes or less; anything longer is counted as the agent idle.

```
python3 compx574/workflow-3/extract-usage.py \
    ~/.claude/projects/-Users-rison-code-Waikato-574-mealie/<session-id>.jsonl
```

The script is generic apart from the commit-subject pattern it segments on, so the other workflows
can run it against their own transcripts if they want a like-for-like breakdown.
