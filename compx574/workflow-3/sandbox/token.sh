#!/bin/sh
# Mint a token the Python oracle accepts, the same way compx574/hurl/run.sh does.
# Printed to stdout for use in self-check curl commands; never written to a file in the repo.
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/../../.." && pwd)
docker run --rm -v "$root":/w/mealie -w /w/mealie/php-w3 mealie-w3-php php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$row = Illuminate\Support\Facades\DB::connection("mealie")->table("users")->orderBy("username")->first();
echo App\Auth\Jwt::encode([
    "sub" => App\Support\Guid::dash($row->id), "rme" => false,
    "iss" => "mealie", "iat" => time(), "exp" => time() + 7200,
], App\Auth\Jwt::secret());
'
