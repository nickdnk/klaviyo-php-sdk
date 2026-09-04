# Live smoke tests

Tooling that runs the SDK against a real (test) Klaviyo account, method by method, and records what
the API actually returns. Not shipped in the Composer dist (`export-ignore`). Outputs (`logs/`,
`results/`, `recordings/`, `webhooks/logs/`), secrets (`.env`, `.oauth*.json`) and the OpenAPI
snapshot (`openapi/`, fetched on demand by `report.php`) are git-ignored.

## Setup

```bash
cp scratch/.env.example scratch/.env               # fill in the test account key
(cd scratch/webhooks && docker compose up -d)     # request sink on :8090; expose it with ngrok for webhooks/OAuth
```

OAuth (needed for `oauth_webhooks`): create an OAuth app in Klaviyo with redirect URL
`https://<ngrok>/oauth/callback`, put id/secret in `.env`, then
`php scratch/oauth.php link` → open the URL → `php scratch/oauth.php exchange`.

## Running

```bash
php scratch/run.php tags                # one suite (scratch/suites/<name>.php)
php scratch/run.php all
php scratch/report.php                  # → scratch/REPORT.md (+ OpenAPI endpoint coverage)
php scratch/sweep.php --dry-run         # find/delete sdk-smoke-* leftovers after aborted runs
php scratch/spec-diff.php old.json new.json   # what changed between two API revisions
php tests/fixtures/build.php scratch/recordings --clean   # refresh the recorded-response corpus
```

Suites prefixed `oauth_` authenticate with `.oauth.json`; all others with the API key. Every resource a
suite creates is named `sdk-smoke-<runId>-*` and deleted in cleanup; things the API cannot delete
(images, flow-owned template copies, metrics, bulk-job records) are listed under "mutations" in the
report. Nothing is ever sent to a human: campaign sends, SMS, push and conversation messages are
skipped by design.

`FINDINGS.md` is the curated log of what the run taught us (SDK changes, API quirks, account
limitations); `REPORT.md` is regenerated from `results/` and not committed. `AGENT_BRIEF.md` is the instruction sheet
used when suites are written by agents.
