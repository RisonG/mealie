# Workflow 3 token usage

Workflow 3 ran in one Claude Code session, using Claude Opus 5 for the whole run. No sub-agents
were launched — the group's W3 prompt forbade them — so there is no second table of delegated
usage, and no second coding tool was used. The session transcript
(`e60c4929-e6d1-4991-807b-6f0d8ecd96ae`) records a `usage` block on every assistant message, so
unlike Workflow 1 the token split is available directly rather than inferred from a bill.

| Model | Input | Output | Cache read | Cache write | Cost | Tokens |
|---|---|---|---|---|---|---|
| Claude Opus 5 | 1,800 | 1,047,991 | 361,217,448 | 12,808,917 | not recorded | 375,076,156 |

The token figure is the whole session, counted from the transcript rather than estimated. It
covers more than the nine rounds: the planning discussion before the workflow started, the
environment setup, the scoring harness, both report sections, the Word exports and this file are
all inside it. So it overstates the cost of the rewrite itself, in the same way Workflow 5's
`/usage` totals do.

**Cost was not recorded.** Claude Code's `/usage` screen reports a dollar figure at API prices, as
it did for Workflow 5, but it was not read during or after this run and the screen reports a
rolling window rather than a per-session total, so it cannot be recovered now. The token counts
above are exact; converting them would need the three input columns priced separately — cached
input is far cheaper than fresh input, and cache writes cost more than both — so no single
multiplication of the 375M figure would be meaningful. Anyone wanting a dollar figure should read
`/usage` while the session is still inside its reporting window.

**Cache read dominates and should not be read as effort.** At 361M it is 96% of the total, and it
is the conversation being re-read on each turn at cached-input rates. One long session inflates it
in a way that several short sessions do not: the same work split across ten fresh sessions would
show a fraction of it with identical output. The column that tracks work produced is **output,
1.05M**. Workflow 5's sub-agent figures (1.5M across five short delegated runs) and this one are
not the same unit and should not share a column without that said.

Wall clock was 10 October 14:26 through 15 October 22:21 NZDT, about 128 hours. Almost all of that
is the agent idle: summing only the gaps between consecutive events of ten minutes or less gives
**2.8 hours of active agent time**. Human time was 44 turns, of which the first 12 set up the
method and the branch; the remaining 32 were short. Eighteen consecutive turns said only
"继续" — continue.

## By round

Each round is one contract, one implementation, a self-check and a score, bounded by its commit.
Produced by `compx574/workflow-3/extract-usage.py`, which segments the transcript on the round
commits.

| Segment | Turns | Tool calls | Output | Cache write | Cache read | Active min |
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
| After round 9 — report sections, Word, this file | 132 | 46 | 194,968 | 7,140,747 | 89,107,136 | 33 |
| **Total** | **900** | **388** | **1,047,991** | **12,808,917** | **361,217,448** | **168** |

Round 1 is a quarter of all output and of all active time on its own, because it carries everything
that was not repeated: reading the shared scoring harness, building the Laravel skeleton, deriving
the `App\Auth\Jwt` contract that `run.sh` requires, writing the sandbox and compare scripts, and
measuring the floor score. The nine rounds are not comparable units of work.

Rounds 6 to 9 average 54k output and 11 active minutes against 93k and 20 minutes for rounds 2 to
5, while covering more endpoints — 21, 24, 41 and 64 against 18, 27, 35 and 30. The serializers
written in rounds 3 to 5 (`Pagination`, `ShoppingSerializer`, `IngredientDisplay`) were reused
rather than rewritten. Cache read does not fall with them, because it scales with conversation
length rather than with work: round 9 read 44M for 14 active minutes.

## What was said

Operator turns in session `e60c4929-e6d1-4991-807b-6f0d8ecd96ae`, from the first message on
10 October at 14:26 NZDT. Agent replies and tool calls are left out; unlike Workflow 1's record
this lists only the human side, because that is the part that distinguishes this workflow.

### Setup — 10 October, 14:26 to 20:03

| Time | Said |
|---|---|
| 14:26 | The full W3 prompt pair, pasted from the group chat: contract first, then implement, no sub-agents, module order given |
| 14:30 | 稍等一下我觉得是我应该需要直接在他的基础上弄. |
| 15:45 | *(interrupted)* then a link to `compx574/hurl/parity.hurl` on the team branch |
| 15:48 | 我应该是直接改写w3 |
| 15:52 | 我不太明白我应该干嘛.稍等哈我找找记录 — followed by the W5 prompt text for comparison |
| 15:54 | 我觉得都OK.你这样你先看一下那个分支.然后在基础上自己改一下 |
| 16:30 | 我应该是用他们的分支上面开发. |
| 16:58 | OK.你就按照dreak的方式做就行了.我们创建3 就对了.然后按照之前的要求继续完成 |
| 17:09 | 我还应该怎么做呢 |
| 20:03 | 直接一个吧. |

### The nine rounds — 10 October 21:23 to 11 October 17:27

Eighteen turns. Seventeen of them were the single word 继续; one was 你需继续.

| Time | Said |
|---|---|
| 10-10 21:23 | 继续 |
| 10-11 01:05 | 继续 |
| 10-11 01:10 | 继续 |
| 10-11 09:43 | 继续 |
| 10-11 11:01 | 继续 |
| 10-11 12:04 | 继续 |
| 10-11 12:22 | 继续 |
| 10-11 12:32 | 继续 |
| 10-11 13:53 | 继续 |
| 10-11 14:47 | 继续 |
| 10-11 15:28 | 继续 |
| 10-11 15:50 | 你需继续 |
| 10-11 16:46 | 继续 |
| 10-11 16:59 | 继续 |
| 10-11 17:04 | 继续 |
| 10-11 17:11 | 继续 |
| 10-11 17:15 | 继续 |
| 10-11 17:27 | 继续 |

**This is the measurement of human involvement for Workflow 3.** The method specified a review gate
between each module's contract and its implementation, and the gates were held — the agent stopped
nine times and waited. But each was passed with "continue" rather than a marked-up contract, so the
evidence log records them as agent-run, the same as Workflow 5's. What W3 varied against W5 is one
agent and finely split modules; it did not vary human review.

### After the rewrite — 11 October 17:28 onwards

| Time | Said |
|---|---|
| 10-11 17:28 | 开pr |
| 10-11 17:44 | 我需要写个这吧 *(screenshot of GitHub's new-PR page)* |
| 10-12 13:33 | 英文版本, |
| 10-12 13:34 | 简单的英文版 |
| 10-12 15:17 | 已关闭就是跟其他人的一起合并了. |
| 10-12 15:19 | 所以你英文版本输出一下?中英文都输出一下吧.我好给下一个人. |
| 10-12 15:37 | word 的方式这样别人能copy |
| 10-12 16:01 | 我的意思不单纯的workflow3 的报告吧 |
| 10-12 16:10 | rison guan 1675407 转出word |
| 10-15 22:18 | 生成一下w3的usage |
| 10-15 22:21 | ?文件不给你了嘛 |

Source: session transcript
`~/.claude/projects/-Users-rison-code-Waikato-574-mealie/e60c4929-e6d1-4991-807b-6f0d8ecd96ae.jsonl`,
read 15 October 2026, via `compx574/workflow-3/extract-usage.py`. Round boundaries from the
`workflow-3: round N` commits on `w3-single-agent`.
