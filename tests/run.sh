#!/usr/bin/env sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)

php "$ROOT/tests/fixtures.php"
php "$ROOT/tests/controller-fixtures.php"
find "$ROOT/SendReputeBundle" "$ROOT/tests" -name '*.php' -print0 |
    xargs -0 -n1 php -l
python3 "$ROOT/package.py"