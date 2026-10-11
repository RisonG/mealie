#!/bin/sh
# Stop the Python oracle, restore the shared DB snapshot, restart it.
# The oracle holds the SQLite file open, so it has to stop before the file is replaced.
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/../../.." && pwd)
container=${MEALIE_DEVCONTAINER:-sleepy_napier}

docker exec "$container" sh -c 'for p in /proc/[0-9]*; do c=$(tr "\0" " " < $p/cmdline 2>/dev/null); case "$c" in *"python mealie/app.py"*) kill ${p#/proc/} ;; esac; done' || true
for i in $(seq 1 20); do
  curl -s -o /dev/null -m 1 http://127.0.0.1:9000/api/app/about || break
  sleep 1
done

cp "$root/dev/data/mealie.w3-snapshot.db" "$root/dev/data/mealie.db"
rm -f "$root/dev/data/mealie.db-journal" "$root/dev/data/mealie.db-wal" "$root/dev/data/mealie.db-shm"

docker exec -d -u vscode -w /workspaces/mealie "$container" bash -lc 'PRODUCTION=False uv run python mealie/app.py > /tmp/w3-python.log 2>&1'
curl -s --retry 40 --retry-delay 2 --retry-all-errors --retry-connrefused -o /dev/null -w 'python=%{http_code} ' http://127.0.0.1:9000/api/app/about
echo "db=$(shasum "$root/dev/data/mealie.db" | cut -c1-16)"
