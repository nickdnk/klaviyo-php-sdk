# Recorded API fixtures

`responses/*.json` are real Klaviyo responses captured against a test account (2026-09-04, API revision
2026-07-15), one file per `(operationId, HTTP status)`. `tests/RecordedResponsesTest.php` replays every
file through `APIClient::hydrateResponse()` / the exception classes and fails when:

- a `type` Klaviyo returned is missing from `TypeRegistry`,
- a resource does not hydrate to its registered class or loses a relationship,
- an attribute Klaviyo returned is not documented as `@property` on the Response class,
- an attribute is dropped on re-serialisation,
- an error body does not parse into `KlaviyoError` objects.

Bodies are scrubbed: tokens/secrets are `REDACTED`, e-mail addresses outside the test domains are hashed, and the
test account's own domain/organisation/integration key (`FIXTURE_SCRUB`, `term` or `term=replacement` entries) are neutralised.

## Refreshing

Recordings come from the live smoke suites; the full record → build → verify procedure is in `scratch/README.md`
("Refreshing the recorded fixtures"). Short form:

1. `SMOKE_RECORD=1` in `scratch/.env`, run the suites → `scratch/recordings/*.jsonl`.
2. `FIXTURE_SCRUB="<same value as SCRUB_TERMS>" php tests/fixtures/build.php scratch/recordings --clean`
3. `vendor/bin/phpunit --filter RecordedResponsesTest` and fix what it reports.

Each fixture records the suite and SDK call it came from under `source`.
