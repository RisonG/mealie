#!/bin/sh
# Score php-w3 from the host. Usage: compx574/workflow-3/sandbox/score.sh <label> [php-directory]
#
# Starts one sandbox container holding three things: socat forwarding 127.0.0.1:9000 to the Python
# oracle on the host (parity.hurl hard-codes that address), the PHP built-in server on
# 127.0.0.1:9001, and the run of compx574/workflow-3/measure.sh. Nothing is published, so the
# candidate is reachable only from inside the container.
set -eu

label=${1:?label required}
php_dir=${2:-php-w3}
root=$(CDPATH= cd -- "$(dirname "$0")/../../.." && pwd)

docker run --rm \
  -v "$root":/w/mealie -w /w/mealie \
  --add-host=host.docker.internal:host-gateway \
  mealie-w3-php sh -c "
set -eu
socat TCP-LISTEN:9000,fork,reuseaddr TCP:host.docker.internal:9000 &
php -S 127.0.0.1:9001 -t $php_dir/public $php_dir/public/index.php > /tmp/php-server.log 2>&1 &

for i in \$(seq 1 40); do
  py=\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9000/api/app/about || true)
  php=\$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9001/api/app/about || true)
  [ \"\$py\" = 200 ] && [ \"\$php\" != 000 ] && break
  sleep 1
done
echo \"oracle=\$py candidate=\$php\"

compx574/workflow-3/measure.sh '$label' '$php_dir' http://127.0.0.1:9001
"
