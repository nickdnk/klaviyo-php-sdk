# Smoke-suite author brief

Goal: exercise every public method of the assigned SDK services against the live Klaviyo test
account, with as many query/body parameters as the OpenAPI spec allows, and record what works.

## Layout (paths relative to the repository root)
- SDK source: `src/` (namespace `nickdnk\Klaviyo`). Services: `src/Services/*Service.php`. Request payload
  classes: `src/Resources/Request/*`. Response classes: `src/Resources/Response/*`. Query builder: `src/Query.php`,
  filters: `src/Filter.php`. Read the service file for exact method signatures before calling.
- Spec: `scratch/openapi/stable.json` (run `php scratch/report.php` once to download it). Use `python3 -c` / `jq` on it to look
  up an operation's query parameters (`paths`) and request body schemas (`components.schemas.<Name>`).
- Harness: `scratch/lib/Harness.php`. Runner: `php scratch/run.php <suite>` (from repo root). Results land in
  `scratch/results/<suite>.json`, human log in `scratch/logs/<suite>.log`.
- Reference suite showing the conventions: `scratch/suites/profiles_lists.php` (do not run it).

## Harness API
```php
return function (Smoke\Harness $h): void { ... };            // suite file shape
$h->step('tags', 'create', 'label', fn(APIClient $c) => $c->tags->create(...), fn($r) => $h->assert(cond, 'msg'));
    // returns the callable's result (also when only the assertion failed), or null if the SDK call threw
$h->skip('tags', 'delete', 'label', 'why');                  // record deliberately skipped method
$h->cleanup('tags', 'delete', 'label', fn(APIClient $c) => $c->tags->delete($id)); // runs LIFO at end, recorded as step
$h->mutation('what changed on the account that cleanup does not undo');
$h->note('anything the report reader should know');
$h->reclassify('account-limitation', 'reason');             // fix classification of the last failed step
$h->waitFor(fn() => ..., timeoutSec, intervalSec, 'what');  // poll async jobs; returns first non-null/true
$h->name('thing')  → "sdk-smoke-<runId>-thing"   (use for EVERY created resource name/external id)
$h->email('x')     → "sdk-smoke+<runId>-x@nickdnktech.com"
$h->webhookUrl()   → public ngrok URL of a request sink (webhooks endpoints are 403 on this account though)
$h->client         → the APIClient (API-key auth)
```
`service` in step() must be the APIClient property name (`tags`, `tagGroups`, `catalogItems`, `reports`, ...),
`method` the SDK method name. That is how the report computes coverage — one step per SDK method call at minimum;
call the same method several times with different Query knobs (fields, additional-fields, include, filter,
sort, page[size], pagination via `links.next` / `next:` argument) and record each as its own step.

## Rules
1. Never send anything to humans: no campaign send jobs, no SMS/push/WhatsApp, no conversation messages. Create
   campaigns/flows only as drafts. If a method would send, `$h->skip()` it with that reason.
2. PII: do not list/fetch existing profiles or print their attributes. Only profiles you create
   (`$h->email(...)`) may be read. Never `var_dump` responses into the log.
3. Delete everything you create (cleanup hooks) — that is how delete endpoints get exercised. Register the
   cleanup immediately after a successful create. Things the API cannot delete: `$h->mutation(...)`.
4. When a call fails with 400/422, read the error dump in `results/<suite>.json` (`http[].requestBody` /
   `responseBody`), decide whether it is your test data (fix and rerun) or the SDK building a wrong request
   (leave failing, `$h->note()` the diagnosis). Do NOT edit anything under `src/` or `tests/` — report suspected
   SDK bugs in your final message with the exact request/response and what you think the fix is.
5. 403 "Advanced KDP" / 402 / feature-not-enabled → let it fail (auto-classified account-limitation) and move on;
   still call every method so the report shows the status.
6. Assert on hydration: response objects are typed (`instanceof` the Response class, `->id`, attributes set,
   nested objects hydrated, relationship ids hydrated via `getRelationship()`), `get()` on unknown id returns null,
   `list()` returns `['data' => [...], 'links' => ?PaginationLinks]`.
7. Also exercise `returnRequest: true` + `$c->executePool([...])` for at least one method of your services.
8. Rerun the suite until only genuine failures remain (SDK bug, account limitation). Each run uses a fresh runId
   and cleans up after itself; if a run aborts, delete leftovers named `sdk-smoke-*` for your resource types.
9. NEVER delete by broad name match (`contains(name,"sdk-smoke")`): other suites run in parallel with other run ids. Only delete ids you created, or names containing YOUR `$h->runId`.
10. Rate limits: the client retries 429 automatically; just be patient. Other suites run in parallel.
11. Work from the repo root; run with `php scratch/run.php <suite>` (network works from the sandbox).

## Final message must contain
- pass/fail/skip counts and the run id
- every remaining failure with classification and a one-line diagnosis
- suspected SDK bugs (request/response excerpt + proposed fix)
- SDK usability gaps you hit (missing constructor params, awkward relationship attachment, etc.)
- anything left on the account that could not be deleted
