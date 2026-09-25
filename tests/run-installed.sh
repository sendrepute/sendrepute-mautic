#!/bin/sh
# Disposable official-release host, not the offline Mautic/Symfony stubs.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
PHP_BIN=${MAUTIC_TEST_PHP:-}
if [ -z "$PHP_BIN" ]; then
  for candidate in /nix/store/*php-with-extensions-8.3.23/bin/php; do
    if [ -x "$candidate" ]; then PHP_BIN=$candidate; break; fi
  done
fi
if [ ! -x "$PHP_BIN" ]; then
  echo 'Set MAUTIC_TEST_PHP to a PHP 8.2/8.3 executable with Mautic extensions.' >&2
  exit 2
fi
"$PHP_BIN" -r 'if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80400) exit(2); foreach (["curl","pdo_mysql","intl","zip"] as $e) if (!extension_loaded($e)) exit(2);' || {
  echo 'PHP 8.2/8.3 with curl, pdo_mysql, intl, zip required.' >&2
  exit 2
}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/sendrepute-mautic-installed.XXXXXX")
chmod 700 "$WORK"
cleanup() {
  if [ -n "${PROXY_PID:-}" ]; then kill "$PROXY_PID" 2>/dev/null || true; fi
  if [ -n "${WEB_PID:-}" ]; then
    pkill -TERM -P "$WEB_PID" 2>/dev/null || true
    kill "$WEB_PID" 2>/dev/null || true
  fi
  if [ -n "${DB_PID:-}" ]; then kill "$DB_PID" 2>/dev/null || true; fi
}
trap cleanup EXIT INT TERM
WEB_PORT=34600
DB_PORT=34601
PROXY_PORT=34602
for port in "$WEB_PORT" "$DB_PORT" "$PROXY_PORT"; do
  "$PHP_BIN" -r '$s=@stream_socket_server("tcp://127.0.0.1:".$argv[1],$e,$m); if (!$s) exit(2); fclose($s);' "$port" || {
    echo "Refusing occupied loopback port $port" >&2
    exit 2
  }
done
ARCHIVE="$WORK/5.2.8.zip"
if [ -n "${MAUTIC_TEST_ARCHIVE:-}" ]; then
  cp -- "$MAUTIC_TEST_ARCHIVE" "$ARCHIVE"
  ARCHIVE_SOURCE=verified-local-archive
else
  curl -fLs --retry 2 --max-time 150 \
    https://github.com/mautic/mautic/releases/download/5.2.8/5.2.8.zip -o "$ARCHIVE"
  ARCHIVE_SOURCE=verified-official-download
fi
echo '924b9ac8ddf7bd78e7ac5222f05c265fa70756975e13266f619b2b7fa0a1b3ec  '"$ARCHIVE" | sha256sum -c -
mkdir "$WORK/release"
unzip -q "$ARCHIVE" -d "$WORK/release"
mkdir -m 700 "$WORK/ledger"
# The native cURL code still uses its fixed production HTTPS hostname; a
# strict loopback CONNECT proxy handles only that host, never forwarding.
# This test-only CA is trusted through PHP curl.cainfo, not system trust.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -keyout "$WORK/proxy.key" -out "$WORK/proxy.crt" \
  -subj '/CN=www.sendrepute.com' \
  -addext 'subjectAltName=DNS:www.sendrepute.com' \
  -addext 'basicConstraints=critical,CA:TRUE' > "$WORK/certificate.log" 2>&1
chmod 600 "$WORK/proxy.key"
python3 "$ROOT/tests/local_paid_proxy.py" "$PROXY_PORT" \
  "$WORK/proxy.crt" "$WORK/proxy.key" "$WORK/paid-arrivals.jsonl" \
  > "$WORK/proxy.log" 2>&1 &
PROXY_PID=$!
sleep 1
kill -0 "$PROXY_PID" 2>/dev/null || {
  echo 'Owned local TLS fixture exited before test' >&2
  exit 1
}
mariadb-install-db --no-defaults --datadir="$WORK/db" \
  --auth-root-authentication-method=normal > "$WORK/db-init.log" 2>&1
mariadbd --no-defaults --datadir="$WORK/db" --socket="$WORK/db.sock" \
  --pid-file="$WORK/db.pid" --bind-address=127.0.0.1 --port="$DB_PORT" \
  --log-error="$WORK/db.log" >/dev/null 2>&1 &
DB_PID=$!
ready=0
for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  kill -0 "$DB_PID" 2>/dev/null || break
  if mariadb-admin --protocol=socket --socket="$WORK/db.sock" -uroot ping >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "$ready" -eq 1 ] || { echo 'Isolated database did not start' >&2; exit 1; }
mariadb --protocol=socket --socket="$WORK/db.sock" -uroot -e \
  "CREATE DATABASE mautic600 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   CREATE USER 'fixture600'@'127.0.0.1' IDENTIFIED BY 'fixture600-local-only';
   GRANT ALL ON mautic600.* TO 'fixture600'@'127.0.0.1';"
cd "$WORK/release"
"$PHP_BIN" -d memory_limit=1G -d sendmail_path=/bin/false bin/console mautic:install \
  "http://127.0.0.1:$WEB_PORT" --force --db_driver=pdo_mysql \
  --db_host=127.0.0.1 --db_port="$DB_PORT" --db_name=mautic600 \
  --db_user=fixture600 --db_password=fixture600-local-only \
  --admin_firstname=Disposable --admin_lastname=Admin --admin_username=admin600 \
  --admin_email=admin600@invalid.test --admin_password='Disposable-600-only!' \
  > "$WORK/install.log" 2>&1 || {
    echo "Mautic installation failed; see $WORK/install.log" >&2
    exit 1
  }
cp -R "$ROOT/SendReputeBundle" "$WORK/release/plugins/SendReputeBundle"
"$PHP_BIN" -d memory_limit=1G bin/console cache:clear --env=prod > "$WORK/cache.log" 2>&1
"$PHP_BIN" -d memory_limit=1G bin/console mautic:plugins:reload --env=prod > "$WORK/plugin.log" 2>&1
"$PHP_BIN" -d memory_limit=1G bin/console debug:router sendrepute_email_preflight --env=prod \
  > "$WORK/route.log" 2>&1
grep -q 'GET|POST' "$WORK/route.log"
mariadb --protocol=tcp -h127.0.0.1 -P"$DB_PORT" -u fixture600 \
  -pfixture600-local-only -D mautic600 -e \
  "INSERT INTO emails (is_published,created_by,name,subject,from_address,from_name,
      custom_html,email_type,read_count,sent_count,variant_sent_count,
      variant_read_count,revision,lang,headers)
   VALUES (0,1,'<img src=x onerror=alert(600)>','Subject','fixture@invalid.test',
      'Fixture','<p>Body</p>','template',0,0,0,0,1,'en','{}');"
SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED=1 \
SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE=local-flock \
SENDREPUTE_MAUTIC_LEDGER_DIR="$WORK/ledger" \
SENDREPUTE_API_KEY=local-only-paid-stub \
HTTPS_PROXY="http://127.0.0.1:$PROXY_PORT" \
https_proxy="http://127.0.0.1:$PROXY_PORT" \
HTTP_PROXY="http://127.0.0.1:$PROXY_PORT" \
http_proxy="http://127.0.0.1:$PROXY_PORT" \
ALL_PROXY="http://127.0.0.1:$PROXY_PORT" \
all_proxy="http://127.0.0.1:$PROXY_PORT" \
NO_PROXY=127.0.0.1,localhost \
no_proxy=127.0.0.1,localhost \
PHP_CLI_SERVER_WORKERS=2 \
  "$PHP_BIN" -d memory_limit=1G -d sendmail_path=/bin/false \
  -d "curl.cainfo=$WORK/proxy.crt" \
  -S "127.0.0.1:$WEB_PORT" > "$WORK/web.log" 2>&1 &
WEB_PID=$!
sleep 2
kill -0 "$WEB_PID" 2>/dev/null || {
  echo 'Owned HTTP host exited before test' >&2
  exit 1
}
python3 "$ROOT/tests/installed_http.py" "http://127.0.0.1:$WEB_PORT" \
  "$DB_PORT" fixture600 fixture600-local-only mautic600 "$WORK/evidence.json" \
  "$WORK/paid-arrivals.jsonl" "$("$PHP_BIN" -r 'echo PHP_VERSION;')" \
  "$(mariadb --version)" "$ARCHIVE_SOURCE"
# The retained copy is deliberately sanitized: no session cookies, fixture
# key, raw HTTP bodies, DB contents, certificates, or absolute work paths.
mkdir -p "$ROOT/tests/evidence"
cp "$WORK/evidence.json" "$ROOT/tests/evidence/installed-5.2.8.json"
printf 'Installed test evidence: %s/evidence.json\n' "$WORK"