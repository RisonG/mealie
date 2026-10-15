#!/usr/bin/env python3
"""Token usage for a Claude Code session, segmented by the round commits.

Usage: python3 compx574/workflow-3/extract-usage.py <session.jsonl>

Claude Code writes one JSON object per line to
~/.claude/projects/<slug>/<session-id>.jsonl. Assistant lines carry a `usage`
block, so the numbers below are read, not estimated.

"Active" time is the sum of gaps between consecutive events of <= 10 minutes;
anything longer is the agent idle waiting for the operator.
"""
import collections
import datetime
import json
import pathlib
import subprocess
import sys

IDLE_GAP = 10 * 60
KEYS = ("output_tokens", "cache_creation_input_tokens", "cache_read_input_tokens", "input_tokens")


def load(path):
    events = []
    for line in pathlib.Path(path).open():
        try:
            d = json.loads(line)
        except ValueError:
            continue
        ts = d.get("timestamp")
        if not ts:
            continue
        m = d.get("message") or {}
        role = m.get("role") or ""
        events.append((
            datetime.datetime.fromisoformat(ts.replace("Z", "+00:00")),
            role,
            (m.get("usage") or {}) if role == "assistant" else {},
            sum(1 for c in (m.get("content") or [])
                if isinstance(c, dict) and c.get("type") == "tool_use"),
        ))
    events.sort(key=lambda e: e[0])
    return events


def round_commits(base="99b22abc5"):
    out = subprocess.run(["git", "log", "--format=%cI|%s", f"{base}..HEAD", "--reverse"],
                         capture_output=True, text=True).stdout.strip().splitlines()
    rounds = []
    for line in out:
        iso, subject = line.split("|", 1)
        if subject.startswith("workflow-3: round") and "—" in subject:
            t = datetime.datetime.fromisoformat(iso).astimezone(datetime.timezone.utc)
            rounds.append((t, subject.split("round ")[1].split(" ")[0]))
    return sorted(rounds)


def segment(events, start, end):
    totals, turns, tools, active = collections.Counter(), 0, 0, 0.0
    window = [e for e in events if (start is None or e[0] > start) and e[0] <= end]
    for t, role, usage, tc in window:
        if role == "assistant":
            turns += 1
            tools += tc
            for k in KEYS:
                totals[k] += usage.get(k, 0) or 0
    for a, b in zip(window, window[1:]):
        gap = (b[0] - a[0]).total_seconds()
        if gap <= IDLE_GAP:
            active += gap
    return totals, turns, tools, active / 60


def main():
    events = load(sys.argv[1])
    rounds = round_commits()
    header = (f"{'segment':<28}{'turns':>6}{'tools':>7}{'output':>11}"
              f"{'cache write':>13}{'cache read':>14}{'active min':>12}")
    print(header)
    print("-" * len(header))

    grand, gt, go, ga = collections.Counter(), 0, 0, 0.0
    prev = None
    for cutoff, name in rounds + [(events[-1][0], "after R9")]:
        totals, turns, tools, active = segment(events, prev, cutoff)
        label = f"round {name}" if name != "after R9" else "after R9 (reports)"
        print(f"{label:<28}{turns:>6}{tools:>7}{totals['output_tokens']:>11,}"
              f"{totals['cache_creation_input_tokens']:>13,}"
              f"{totals['cache_read_input_tokens']:>14,}{active:>12.0f}")
        grand.update(totals); gt += turns; go += tools; ga += active
        prev = cutoff

    print("-" * len(header))
    print(f"{'TOTAL':<28}{gt:>6}{go:>7}{grand['output_tokens']:>11,}"
          f"{grand['cache_creation_input_tokens']:>13,}"
          f"{grand['cache_read_input_tokens']:>14,}{ga:>12.0f}")
    span = (events[-1][0] - events[0][0]).total_seconds() / 3600
    print(f"\nfresh input {grand['input_tokens']:,} | all four summed "
          f"{sum(grand[k] for k in KEYS):,}")
    print(f"wall clock {span:.1f} h | active {ga/60:.1f} h | idle {span - ga/60:.1f} h")


if __name__ == "__main__":
    main()
