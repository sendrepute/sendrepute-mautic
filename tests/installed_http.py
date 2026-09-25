#!/usr/bin/env python3
"""Authenticated HTTP assertions against the disposable, installed Mautic host."""
import concurrent.futures
import http.cookiejar
import json
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

base, port, user, password, database, output, metrics, php_version, db_version, archive_source = sys.argv[1:]
checks = {}
observations = {}


def require(name, condition):
    checks[name] = bool(condition)
    if not condition:
        raise AssertionError(name)


def sql(statement):
    subprocess.run(
        ["mariadb", "--protocol=tcp", "-h127.0.0.1", "-P" + port,
         "-u" + user, "-p" + password, "-D", database, "-e", statement],
        check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )


def arrivals():
    try:
        with open(metrics, encoding="utf-8") as trace:
            return [json.loads(line) for line in trace if line.strip()]
    except FileNotFoundError:
        return []


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def request(self, path, data=None):
        req = urllib.request.Request(
            base + path,
            data=None if data is None else urllib.parse.urlencode(data).encode(),
        )
        try:
            response = self.opener.open(req, timeout=25)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode("utf-8", "replace"), response.headers

    def login(self, username):
        status, body, _ = self.request("/s/login")
        require("native_login_form", status == 200)
        token = re.search(r'name="_csrf_token" value="([^"]+)"', body)
        require("native_login_csrf_present", token is not None)
        status, body, _ = self.request(
            "/s/login_check",
            {"_username": username, "_password": "Disposable-600-only!",
             "_csrf_token": token.group(1)},
        )
        require("native_login_" + username, status == 200 and "Dashboard" in body)


def intent(body, field):
    match = re.search(r'name="' + field + r'" value="([^"]+)"', body)
    require(field + "_present", match is not None)
    return match.group(1)


try:
    anonymous = Client()
    code, body, _ = anonymous.request("/s/sendrepute/email/1/preflight")
    require("anonymous_denied_by_native_login", code == 200 and "login-form" in body)

    admin = Client()
    admin.login("admin600")
    route = "/s/sendrepute/email/1/preflight"
    code, body, headers = admin.request(route)
    require("native_admin_route", code == 200 and "SendRepute draft preflight" in body)
    require("stored_xss_escaped", "&lt;img src=x onerror=alert(600)&gt;" in body
            and "<img src=x onerror=alert(600)>" not in body)
    require("private_uncached_result", "no-store" in headers.get("Cache-Control", ""))
    token, nonce = intent(body, "_token"), intent(body, "intent")
    code, body, _ = admin.request(route, {"_token": "forged", "intent": nonce,
                                           "paid_confirmation": "yes"})
    require("native_csrf_rejected", code == 200 and "confirmation expired" in body)
    code, body, _ = admin.request(route, {"_token": token, "intent": nonce,
                                           "paid_confirmation": "yes"})
    require("consumed_intent_not_replayable", "confirmation expired" in body)
    code, body, _ = admin.request(route)
    token, nonce = intent(body, "_token"), intent(body, "intent")
    code, body, _ = admin.request(route, {"_token": token, "intent": nonce})
    require("paid_consent_required", "Explicit paid-analysis confirmation" in body)
    code, body, _ = admin.request(route)
    token, nonce = intent(body, "_token"), intent(body, "intent")
    sql("UPDATE emails SET subject='Changed after confirmation' WHERE id=1")
    code, body, _ = admin.request(route, {"_token": token, "intent": nonce,
                                           "paid_confirmation": "yes"})
    require("draft_change_invalidates_intent", "confirmation expired" in body)
    code, body, _ = admin.request(route)
    token, nonce = intent(body, "_token"), intent(body, "intent")
    sql("UPDATE emails SET subject='Subject' WHERE id=1")
    code, body, _ = admin.request(route, {"_token": token, "intent": nonce,
                                           "paid_confirmation": "yes"})
    require("stale_intent_after_restore_rejected", "confirmation expired" in body)

    sql("""INSERT INTO roles (is_published,name,is_admin,readable_permissions)
           VALUES (1,'Disposable non-admin',0,'a:0:{}')""")
    sql("""INSERT INTO users (role_id,is_published,username,password,first_name,last_name,email)
           SELECT (SELECT MAX(id) FROM roles),1,'limited600',password,'Limited','User',
                  'limited600@invalid.test' FROM users WHERE username='admin600'""")
    limited = Client()
    limited.login("limited600")
    code, body, _ = limited.request(route)
    require("native_nonadmin_forbidden", code == 403 and "Access denied." in body)
    code, body, _ = limited.request(route, {"_token": token, "intent": nonce,
                                             "paid_confirmation": "yes"})
    require("native_nonadmin_post_forbidden", code == 403)
    code, body, _ = admin.request("/s/sendrepute/email/999999/preflight")
    require("missing_draft_404", code == 404)

    code, body, _ = admin.request(route)
    token, nonce = intent(body, "_token"), intent(body, "intent")
    payload = {"_token": token, "intent": nonce, "paid_confirmation": "yes"}
    # Same-session retries can serialize at PHP's native session lock; the
    # verified transport count is authoritative, not a response substring.
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        results = list(executor.map(lambda _: admin.request(route, payload)[1], range(2)))
    require("repeated_post_at_most_one_consumption",
            sum("Advisory result:" in r for r in results) == 1
            and sum("confirmation expired" in r or "in progress" in r for r in results) == 1)
    require("same_intent_exactly_one_paid_stub_call",
            len(arrivals()) == 1 and all(row["valid"] for row in arrivals()))
    code, body, _ = admin.request(route, payload)
    require("retry_after_consumption_rejected", "confirmation expired" in body)
    require("retry_did_not_reach_transport", len(arrivals()) == 1)

    # Independent admin sessions have no common PHP session lock. Start first
    # POST, wait for the local TLS fixture to record its arrival, and submit
    # the second while that fixture is deliberately holding the first request.
    other = Client()
    other.login("admin600")
    _, body, _ = admin.request(route)
    token_a, nonce_a = intent(body, "_token"), intent(body, "intent")
    _, body, _ = other.request(route)
    token_b, nonce_b = intent(body, "_token"), intent(body, "intent")
    require("independent_paid_intents", nonce_a != nonce_b)
    first = {"_token": token_a, "intent": nonce_a, "paid_confirmation": "yes"}
    second = {"_token": token_b, "intent": nonce_b, "paid_confirmation": "yes"}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        pending = executor.submit(admin.request, route, first)
        deadline = time.monotonic() + 10
        while len(arrivals()) < 2 and time.monotonic() < deadline:
            time.sleep(0.02)
        require("first_native_post_inside_delayed_tls_transport",
                len(arrivals()) == 2 and not pending.done())
        code, rejected, _ = other.request(route, second)
        second_finished_during_first_transport = not pending.done()
        _, accepted, _ = pending.result(timeout=20)
    require("actual_parallel_native_lock_rejection",
            code == 200 and "Another paid preflight" in rejected
            and second_finished_during_first_transport
            and "Advisory result:" in accepted)
    observations["separate_sessions_second_finished_before_first"] = (
        second_finished_during_first_transport
    )
    require("parallel_post_only_one_tls_stub_call",
            len(arrivals()) == 2 and all(row["valid"] for row in arrivals()))
    require("no_automatic_retry_of_locked_post", len(arrivals()) == 2)
    print(json.dumps({"status": "passed", "checks": checks}, sort_keys=True))
except Exception as exc:
    print(json.dumps({"status": "failed", "checks": checks, "error": str(exc)},
                     sort_keys=True))
    raise
finally:
    with open(output, "w", encoding="utf-8") as evidence:
        json.dump({"status": "passed" if checks and all(checks.values()) else "failed",
                   "checks": checks, "host": "Mautic 5.2.8 official release installed",
                   "php": php_version, "mariadb": db_version,
                   "archive_source": archive_source,
                   "observations": observations,
                   "local_tls_stub_calls": len(arrivals()),
                   "billing": "synthetic local-only key and zero-charge TLS fixture; no real API",
                   "mail_hook": "N/A: advisory plugin has no mail send hook"}, evidence, indent=2)