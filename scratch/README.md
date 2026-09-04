# Live smoke tests

Runs the SDK against a real **test** Klaviyo account, method by method, and records what comes back. The recordings become
`tests/fixtures/responses` (replayed by `RecordedResponsesTest`). Not in the Composer dist.

- `run.php`, `suites/*.php`, `lib/Harness.php` — the suites and their harness
- `report.php` → `REPORT.md` (git-ignored) — per-step results + endpoint coverage
- `sweep.php` — deletes `sdk-smoke-*` leftovers after an aborted run
- `spec-diff.php`, `api_versions/<revision>.url` — commit-pinned link to Klaviyo's `stable.json` per API revision; `report.php` and `tests/fixtures/build.php` load the one matching `APIClient::API_REVISION` (cached in `.cache/`)
- `oauth.php`, `webhooks/` — OAuth bootstrap and the nginx request sink
- `KLAVIYO_NOTES.md` — API behaviour that is not in any docblock

## Setup

```bash
cp scratch/.env.example scratch/.env             # keys are documented inline
(cd scratch/webhooks && docker compose up -d)   # request sink on :8090 → webhooks/logs/webhooks.jsonl
```

OAuth (needed by `oauth_*` suites; webhooks are 403 with an API key):

1. Create an app in Klaviyo, grant **all** scopes, redirect URL `http://localhost:8090/oauth/callback`. Put id/secret in `.env`.
2. `php scratch/oauth.php link` → open the URL, approve → `php scratch/oauth.php exchange` reads the code from the sink log and
   writes `.oauth.json` (access token, refresh token, expiry). `status` / `refresh` / `revoke` exist too.

Webhooks additionally need the sink reachable from the internet: `ngrok http 8090`, URL into `WEBHOOK_BASE_URL`.

Gotcha: tracker blocklists carry `a.klaviyo.com`; if everything is a connection error, check DNS.

## Running

```bash
php scratch/run.php tags coupons        # scratch/suites/<name>.php; or: all
php scratch/report.php                  # → REPORT.md
php scratch/sweep.php --dry-run         # leftovers from aborted runs (never touches profiles)
```

Everything a suite creates is `sdk-smoke-<runId>-*` and deleted LIFO at the end. Images, flow-copied templates, metrics and
bulk-job records cannot be deleted and accumulate; suites report them as "mutations". Nothing is ever sent to a human.

## Writing a suite

`suites/profiles_lists.php` is the reference. A suite is `return function (Smoke\Harness $h): void { … };`

```php
$h->step('tags', 'create', 'label', fn(APIClient $c) => $c->tags->create(...), fn($r) => $h->assert($cond, 'msg'));
$h->cleanup('tags', 'delete', 'label', fn(APIClient $c) => $c->tags->delete($id));   // register right after a successful create
$h->skip('tags', 'delete', 'label', 'why');      $h->mutation('what stays on the account');      $h->note('…');
$h->waitFor(fn() => …, timeout, interval, 'what');   // async jobs
$h->name('x') / $h->email('x') / $h->webhookUrl()     // sdk-smoke-<runId>-x, sdk-smoke+<runId>-x@TEST_DOMAIN, sink URL
```

`service`/`method` must be the `APIClient` property and SDK method name — that is how coverage is computed; one step per call,
several steps per method to cover `Query` knobs. Rules: create campaigns/flows only as drafts and never send; read only
profiles you created; delete by id or by your own `runId`, never by broad name match (suites run in parallel); a 400/422 is
either your test data (fix) or the SDK building a wrong request (leave failing, `note()` the diagnosis, report it); 403
entitlement errors are expected on some endpoints, keep going.

## Refreshing the fixtures

```bash
# SMOKE_RECORD=1 and SCRUB_TERMS in scratch/.env, then:
php scratch/run.php all                                                   # → recordings/*.jsonl
FIXTURE_SCRUB="$SCRUB_TERMS" php tests/fixtures/build.php scratch/recordings --clean
vendor/bin/phpunit --filter RecordedResponsesTest
```

## Bumping the API revision

```bash
php scratch/spec-diff.php --save    # diff pinned API_REVISION document vs klaviyo/openapi main; pins main as api_versions/<new>.url
```

Set `APIClient::API_REVISION`, update the classes the diff points at, `run.php all`, refresh fixtures, `composer test`.
Major release. Commit the new `.url`; keep the old one as the next baseline.
