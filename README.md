# SendRepute for Mautic

This is an installable **Mautic 5.x** plugin providing an administrator-initiated,
paid preflight for a selected saved email draft. It uses Mautic's supported plugin
bundle configuration, route, controller action injection, `CorePermissions`, CSRF
manager, and `VIEW_INJECT_CUSTOM_BUTTONS` extension point.

## Install and configure

Build the allowlisted ZIP:

```sh
git clone https://github.com/sendrepute/sendrepute-mautic.git
cd sendrepute-mautic
python3 package.py
```

Upload `dist/SendReputeBundle-0.1.0.zip` with Mautic's plugin installer (or extract
the `SendReputeBundle` directory into Mautic's `plugins/` directory), then run the
normal Mautic plugin reload/cache-clear procedure.

Configure server-side environment variables:

* `SENDREPUTE_API_KEY`: customer API bearer credential with `classify` permission.
* `SENDREPUTE_MAUTIC_PAID_ANALYSIS_ENABLED=1`: global opt-in. It is intentionally
  disabled for every new installation until explicitly enabled.
* `SENDREPUTE_MAUTIC_ATOMIC_LOCK_MODE=local-flock`: explicit confirmation that
  Mautic runs on one web node and PHP `flock` is a valid process-shared lock on
  that node. Do not set this on a multi-node deployment.
* `SENDREPUTE_MAUTIC_LEDGER_DIR`: an existing, persistent, absolute local
  directory owned by the PHP user with mode `0700`. Do not use `/tmp`, an
  ephemeral container layer, a symlink, or network storage with uncertain
  `flock`/rename/fsync semantics.

The credential is never accepted from a browser, email, URL, or Mautic field. The
API destination is fixed to `https://www.sendrepute.com/api/v1/classify`; redirects
are refused, TLS peer/host verification is mandatory, and URL customization is not
supported. Keep the environment variables outside logs and source control.

Open a saved email as an actual Mautic administrator and choose **SendRepute
preflight**. Both the button and controller require `CorePermissions::isAdmin()`;
the controller also repeats Mautic's `viewown`/`viewother` entity access check. A
POST requires Mautic CSRF validation, a ten-minute one-use intent bound to the
current saved sender/subject/body, and an explicit paid-operation checkbox. Intent
validation, durable nonce consumption, and the paid request run while an exclusive
per-email `flock` is held, so concurrent PHP processes on that node cannot both
spend. Before paid HTTP, a content-free ledger record keyed by a one-way digest of
session ID plus nonce is atomically replaced, flushed, and retained for 24 hours.
This ledger—not session persistence—is authoritative, so a stale session snapshot
saved after lock release cannot resurrect a consumed nonce. It stores no message,
credential, raw session ID, or raw nonce; expired entries are cleaned during
consumption and each per-email ledger fails closed at 4,096 live entries. Each
intent performs at most one request and never automatically retries. The API's account-scoped
24-hour content receipt protection governs repeated identical manually confirmed
requests.

## Scope and security semantics

Only sender **display name**, subject, and saved body are transmitted. Recipient
addresses, contact data, headers, attachments, and campaign membership are never
read or sent. Mautic substitution tokens remain unexpanded. The plugin neither
modifies the email nor invokes a Mautic send operation, so campaigns and eventual
per-recipient substitution remain unchanged.

The result is **advisory only**. A finite spam probability from 0 through 1, label,
confidence, and billing fields are validated before display. Malformed input,
malformed/oversized responses, transport/TLS/redirect failures, authentication/
permission errors, insufficient balance, and rate limits are shown separately
from a spam result. Failure is closed for the preflight: no decision is displayed.
There is no send-time enforcement or blocking mode.

Classification is a variable-price paid operation. The confirmation page does not
invent an exact quote; administrators must check account pricing/spend limits, and
the validated response displays the actual charged millicents (or receipt replay).
No analysis guarantees inbox placement or deliverability.

## Compatibility and honest limitations

Source compatibility target: Mautic 5.x with PHP 8.1+ and ext-curl. The implementation
was grounded against the Mautic 5.x `Email`, `EmailModel`, `CorePermissions`,
`CustomButtonEvent`, `ButtonHelper`, controller, routing, and plugin config source.
It does **not** claim Mautic 4 or 6 compatibility; Mautic 4 uses legacy controller
route notation and Mautic 6 may change extension contracts. Offline fixtures do
not substitute for installation testing against each Mautic patch. No live Mautic
installation was tested while implementing this package.

Linux/POSIX single-node storage is the supported lock/ledger environment.
Multi-node/web-cluster deployments are unsupported because local `flock` cannot
provide a cross-host paid-operation lock. The plugin stays disabled unless the
single-node lock mode and persistent private ledger directory are explicitly
configured. A future cluster-capable version would need an atomic shared lock
with ownership and expiry semantics.

Mautic's pre-send events do not provide a documented, transactionally reliable
way for this plugin to synchronously gate every direct, queued, campaign, retry,
and third-party transport path without paid-call duplication and queue failure
risk. Consequently this release deliberately does not subscribe to send hooks and
does not claim that it can block sends.

For builder drafts lacking `customHtml`, string content slots are concatenated in
stored order. The analysis therefore covers the saved draft representation, not
recipient-specific rendered output. Unsaved editor changes must be saved first.

## Offline tests

No test performs a network request, paid API operation, or email send:

```sh
sh tests/run.sh
```

Fixtures cover field minimization/token preservation, complete required response
members, malformed JSON/bodies, finite score validation, status classification,
redirect behavior, local lock exclusion, secret-free errors, route constraints,
administrator plus resource permission checks, CSRF rejection, one-use replay,
stale-session resurrection after lock release, independent session scoping, and
saved-content changes. Controller fixtures invoke the production action with
offline classes mirroring the Mautic/Symfony method signatures; they are not a
live-framework installation test. The package script includes only allowlisted
source types and follows symlinks nowhere.

## Support and security

Use GitHub issues for reproducible, non-sensitive bugs. Account support and
private vulnerability reports: support@sendrepute.com. Never include API keys,
customer messages, sessions or unredacted logs. See [SECURITY.md](SECURITY.md).