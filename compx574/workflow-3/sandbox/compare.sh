#!/bin/sh
# Self-check for the implementation rounds. Sends the same request to the Python oracle and to
# php-w3 and reports status and body differences. compx574/hurl/ is not read or run here: this is
# how a round verifies itself while parity.hurl stays an acceptance test.
# Usage: compx574/workflow-3/sandbox/compare.sh <requests-file>
set -eu

req=${1:?requests file required}
root=$(CDPATH= cd -- "$(dirname "$0")/../../.." && pwd)

docker run --rm -v "$root":/w/mealie -w /w/mealie \
  --add-host=host.docker.internal:host-gateway \
  mealie-w3-php sh -c "
set -eu
socat TCP-LISTEN:9000,fork,reuseaddr TCP:host.docker.internal:9000 &
php -S 127.0.0.1:9001 -t php-w3/public php-w3/public/index.php > /tmp/php-server.log 2>&1 &
for i in \$(seq 1 40); do
  curl -s -o /dev/null http://127.0.0.1:9001/api/app/about && break || sleep 1
done

token=\$(cd php-w3 && php -r '
require \"vendor/autoload.php\";
\$app = require \"bootstrap/app.php\";
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\$row = Illuminate\Support\Facades\DB::connection(\"mealie\")->table(\"users\")->orderBy(\"username\")->first();
echo App\Auth\Jwt::encode([\"sub\"=>App\Support\Guid::dash(\$row->id),\"rme\"=>false,\"iss\"=>\"mealie\",\"iat\"=>time(),\"exp\"=>time()+3600], App\Auth\Jwt::secret());
')

same=0; diff=0
while IFS='|' read -r method path auth body; do
  [ -z \"\$method\" ] && continue
  case \"\$method\" in \\#*) continue ;; esac
  hdr=''
  [ \"\$auth\" = 'auth' ] && hdr=\"Authorization: Bearer \$token\"

  set -- -s -X \"\$method\" -w '\n%{http_code}'
  [ -n \"\$hdr\" ] && set -- \"\$@\" -H \"\$hdr\"
  [ -n \"\$body\" ] && set -- \"\$@\" -H 'Content-Type: application/json' -d \"\$body\"

  for base in http://127.0.0.1:9000 http://127.0.0.1:9001; do
    out=\$(curl \"\$@\" \"\$base\$path\" 2>/dev/null || true)
    code=\$(printf '%s' \"\$out\" | tail -1)
    body=\$(printf '%s' \"\$out\" | sed '\$d' | head -c 400)
    if [ \"\$base\" = http://127.0.0.1:9000 ]; then pc=\$code; pb=\$body; else hc=\$code; hb=\$body; fi
  done

  if [ \"\$pc\" = \"\$hc\" ] && [ \"\$pb\" = \"\$hb\" ]; then
    same=\$((same+1)); printf 'OK    %-4s %-46s %s\n' \"\$method\" \"\$path\" \"\$pc\"
  else
    diff=\$((diff+1))
    printf 'DIFF  %-4s %-46s py=%s php=%s\n' \"\$method\" \"\$path\" \"\$pc\" \"\$hc\"
    [ \"\$pb\" != \"\$hb\" ] && { printf '        py  %s\n' \"\$pb\"; printf '        php %s\n' \"\$hb\"; }
  fi
done < '$req'

echo
echo \"identical=\$same differing=\$diff\"
"
