# Live smoke run against Klaviyo — findings (2026-09-04)

Curated record of what running every SDK method against a real Klaviyo test account taught us.
`php scratch/report.php` regenerates the per-step `REPORT.md` (git-ignored) from `results/`; this file is the distilled version.

## 1. Result

| Suite | pass | fail | skip | Remaining failures |
|---|---|---|---|---|
| profiles_lists | 73 | 0 | 0 | |
| catalogs | 114 | 0 | 2 | probes for unsupported `include`/`page[size]` |
| coupons | 54 | 0 | 0 | |
| tags | 66 | 0 | 4 | no flows on account; default tag group undeletable |
| templates | 51 | 0 | 0 | |
| segments | 27 | 0 | 0 | |
| oauth_webhooks | 20 | 0 | 0 | |
| flows | 64 | 1 | 1 | expected 409 deleting a flow-owned template copy |
| metrics | 59 | 2 | 0 | mapped-metric daily update limit (2/day) |
| campaigns | 47 | 7 | 3 | 6 deliberate contract probes, 1 recipient estimation never materialising |
| settings | 71 | 9 | 4 | 8 × webhooks 403 with API key, 1 × Klaviyo 500 |
| custom_objects | 31 | 20 | 3 | object-type entitlement missing → cascade of 404s on placeholders |
| push_tokens | 6 | 3 | 4 | account cannot process push tokens (403) |

- 326 of 345 OpenAPI operations called. Never called: client-side (`/client/*`) endpoints, `send_campaign`, `cancel_campaign_send`,
  `create_conversation_message` (would message a human), `update_review` (no reviews), `delete_push_token` / `get_profile_id_for_push_token`
  (no tokens), `get_flow_ids_for_tag` (no flows).
- No open SDK defects. Every SDK bug found is fixed (section 3) and covered by unit tests; 596 tests pass on PHP 8.3 and 8.5.
- Account left as found: sweep reports zero `sdk-smoke-*` resources; unavoidable residue in section 6.

## 2. Setup facts

- Test account `WBhXHN` (Europe/Copenhagen). Private API key for most suites; a test OAuth app (all 47 scopes) for `oauth_*`.
- Webhook/OAuth callbacks land on an nginx request sink (`scratch/webhooks`, :8090) exposed through ngrok; it logs headers + raw
  body as JSON lines, which is what `parseWebhookRequest` is verified against.
- A local DNS blocklist resolved `a.klaviyo.com` to `0.0.0.0` (tracker lists carry it); whitelist at the resolver.
- Synthetic profiles use `sdk-smoke+<run>-*@nickdnktech.com`; everything created is named `sdk-smoke-<run>-*`.

## 3. SDK changes made because of the run

Hydration and errors
1. **`included` compound documents are hydrated** and spliced into the matching relationship identifiers, so
   `getRelationship('tags')->data[0]->name` works after `include('tags')`; `$result['included']` is a hydrated list; cyclic
   includes stop at the back-reference; identifier `meta` (e.g. `relationship_id`) is kept and merged. Before, `include=` cost a
   bigger response and delivered nothing beyond ids.
2. **Empty relationships survive**: `data: []` and `data: null` hydrate to `Relationship` objects; links-only relationships get
   `hasData = false`; `Relationship::ids()` added. Before, `getRelationship('tags')` was null for "no tags".
3. **`RelationshipLinks::$self` nullable** — bulk import jobs return `links.related` without `self`; hydration threw a `TypeError`.
4. **`KlaviyoError`** typed error entity; `ClientException::getErrors()` returns `list<KlaviyoError>`, plus `getFirstError()`,
   `getErrorsWithCode()`, `getRawErrors()`. `ProfileService` unsupported-region retry rewritten on it.
5. `Response\FlowMessageDefinition` re-documented as the spec's flat `FlowEmail|FlowSms|FlowPush|…` payload (the old docblock
   described the create-time envelope; every property read null). `Response\CampaignMessage` documents and hydrates the flat
   variant that `POST /campaign-message-assign-template` returns. `Profile` (+`anonymous_id`, `whatsapp_bsuid`,
   `predictive_analytics`, `joined_group_at`), `KlaviyoList::$profile_count`, `MetricProperty::$sample_values`,
   `CatalogVariant::$inventory_policy` (int) corrected.

Requests
5b. **Phone-region retry removed from `ProfileService`** (`stripUnsupportedRegionProfiles`, `executeWithRegionRetry`, the
    auto-retry inside `subscribe()`/`unsubscribe()`): that was the original consumer's business policy, not SDK behaviour. `subscribe()`/`unsubscribe()`
    are plain POSTs; a rejected batch surfaces as `ClientException` and `KlaviyoError::indexIn('/data/attributes/profiles/data')`
    yields the offending indexes for whatever policy the application applies. the original consumer's `KlaviyoHandler` (two
    `executeWithRegionRetry` call sites) must carry that logic itself when it migrates.
6. **`Explicit::null()/emptyList()/emptyObject()`** markers: `Resource` serialisation drops nulls and empty arrays, so nullable PATCH
   attributes could be set but never cleared.
7. **`CreateCustomMetric`/`UpdateCustomMetric` no longer send a `metrics` relationship** (spec has none; API answered 400).
8. `UpdateMappedMetric::unset()` — clearing a mapping needs `relationships.metric.data = null`; `{"type":"metric","id":null}` is rejected.
9. Helpers for things that had to be hand-built: `SubscriptionCreateJob(..., listId)`/`forList()`, `SubscriptionDeleteJob(..., listId)`,
   `BulkImportJob(..., listIds)`/`forLists()`, `CreateWebhook(..., topics, description)` + `setTopics()` on create/update,
   `CreateTagGroup(name, exclusive)`, `CampaignService::clone(?Query)`.
10. Docblocks carrying server rules the spec omits: coupon `external_id` regex and threshold, web-feed name regex, form definition
    rules, image PATCH full-replace, subscription-job profile creation rules (section 5).

Webhooks and OAuth
11. **`WebhookTopicId` enum removed.** Topics are an account-specific resource (one per metric plus system topics), so
    `Resources\Shared\WebhookTopic` now carries the system topics as constants plus `of()`, `is()`, `integration()`, `slug()`,
    `isSystemTopic()`; `Response\WebhookTopic` registered; `parseWebhookRequest` yields a `WebhookTopic` per event and no longer
    drops unfamiliar topics; `WebhookRequest::eventsFor()` added. Consumer migration: `WebhookTopicId::x` → `WebhookTopic::X`,
    `$e['topic'] === …` → `$e['topic']->is(…)`.
12. OAuth docs: the access token rotates on refresh, the refresh token did not (old one stayed valid); README/`OAuthCredentials`
    say "may rotate, persist the whole object". Revocation documented as *disconnecting*, not a step of the normal flow.
13. `parseWebhookRequest` docblock + README state explicitly that the `Klaviyo-Timestamp` header is HMAC input only (section 5).

Transport
14. `RetryPolicy`: exponential backoff (`base × 2^(attempt-1)`, capped by `maxDelaySeconds`, ±50 % jitter) per Klaviyo's guidance;
    `Retry-After` still wins uncapped. `Http\RateLimit` + `APIClient::getLastRateLimit()` expose `RateLimit-*` headers.
    `RetryPolicy`, `Explicit` are `final readonly`.

Packaging and tests
15. `psr/http-client-implementation` + `psr/http-factory-implementation` virtual requirements; `.gitattributes` `export-ignore` and
    `archive.exclude` so dist installs ship only the library.
16. **Recorded-response corpus** `tests/fixtures/responses` (421 real responses, 327 operations, scrubbed) replayed by
    `RecordedResponsesTest` (hydration, registry coverage, relationship survival, re-serialisation, `@property` completeness,
    error mapping). New tests: `HydrationTest`, `ExplicitTest`, `RequestHelpersTest`, `ClientExceptionTest`, `RateLimitTest`.
17. **Service tests assert hydration against the corpus** (`tests/Fixtures.php`): 242 hand-written response bodies in 17 test
    files replaced by recorded ones wherever a test reads hydrated content; request-side assertions and infrastructure tests
    (retry, refresh, pooling, transport) keep synthetic responses. What the invented mocks had wrong: bulk jobs answer
    `processing` (never `queued`) with zero counts; `merge_profiles` is 202 with an identifier-only body; template
    delete/clone/render and `create_coupon_code` answer 200; reports always carry a UUID id; sparse fieldsets drop relationships
    as well as attributes; `sample_values` is null even when requested; flow-message `channel` is `Email`; the `image`
    relation of an email message is `data: null`; `low_balance_threshold` echoes as a string; new tags come back attached to
    the default group; links-only vs. empty relationships differ per endpoint (`Relationship::$hasData`).

## 4. Open SDK usability gaps (not addressed)

- `executePool()` returns `data` only, so pooled collection calls lose `links.next`.
- Catalogs: no composite-id helper (`$custom:::$default:::<external_id>` hand-built for update/bulk jobs); relationship methods want
  `Shared\*` identifier objects while create/update take `string[]`; `CreateCatalogVariant` has 9 positional required args;
  back-in-stock subscriptions are write-only (cannot be deleted).
- Flows/forms/custom objects: definitions are raw nested arrays with no builders; `UpdateFlowAction` docblock lists kebab-case types
  while the read filter needs upper-snake; no helper mapping `temporary_id` → assigned ids; `CreateObjectType` needs
  `$schema->{'source-mapping'} = $m->wrapData()` to embed a mapping.
- Campaigns: `CampaignAudiences` turns `[]` into null (use `Explicit::emptyList()`); `CampaignTrackingOptions` has no constructor;
  `custom_tracking_params` untyped (needs `utm_source` + `utm_medium`); `CampaignSendStrategy::at()` takes strings, not `DateTimeInterface`.
- `TagService::*Ids()` and `TagGroupService::tags()/tagIds()` take no `?Query`/`$next` although the traits support it.
- `BulkCreateCouponCodesJob` repeats the coupon id per code; a `forCoupon()` helper would fit the common case.
- `Update*` classes expose attributes only through `@property` + `__set` (`PatchProfile` meta via `addMeta('patch_properties', …)`).
- `ImageService::uploadFromFile()` takes bytes, not a path; `CreateTemplate` has no `definition` constructor path.

## 5. Klaviyo API behaviour worth knowing

Authentication and limits
- Webhooks and webhook topics are **OAuth-app only**: a private API key gets 403 "You must have Advanced KDP enabled" on all
  seven methods; with an OAuth token everything works.
- `POST /api/object-types` → 403 "Entitlement to create object type not found" on this account (data sources and record
  ingestion work). `POST /api/push-tokens` → 403 "Company is not able to process push tokens". Mapped metrics allow 2 updates per
  slot per day (403 afterwards).
- `page[size]` maxima: lists/segments/templates 10, web feeds 20, tag groups 25, tags/flows 50, most others 100, tracking settings 1;
  metrics, custom/mapped metrics, object types, suppression- and bulk-job listings, ingestion logs accept no `page[size]` (cursor only).

Webhooks
- Delivery within ~1–3 min; deliveries are account-wide and batched (one POST may carry events for several profiles).
  Signature = hex HMAC-SHA256 of `body . Klaviyo-Timestamp` with the webhook secret. **The `Klaviyo-Timestamp` header clock is
  wrong** (`Fri, 04 Sep 2026 18:16:52 GMT` sent at 13:16:52 GMT): use it as HMAC input only; validate age on `body.meta.timestamp`.
- `endpoint_url` is masked in responses (`https://host/*****`); `PATCH /api/webhooks` ignores `"description": null`.
- Topic ids are open-ended: `GET /api/webhook-topics` lists `event:klaviyo.*` system topics plus `event:<integration>.<metric>` for
  every metric, including custom API metrics.

OAuth
- Authorization-code + PKCE flow and automatic 401 refresh verified end to end. Refresh re-issues the same refresh token (old one
  remains valid); only the access token changes, and the previous access token also keeps working until it expires (verified:
  200 with the old token right after a refresh), so a refresh never cuts off a concurrent process.

Profiles, subscriptions, segments
- Bulk subscribe **with a list** creates a new profile and marks it SUBSCRIBED within ~1 min; with `historical_import: true` and no
  list it also works (~2–3 min); **without a list and without historical_import a brand-new email never becomes a profile** (waited
  4 min, also for a gmail.com address). No job-status endpoint exists.
- Suppression jobs are XOR: profiles *or* `list_id` *or* `segment_id`. Suppression/bulk-import job listings use `created_at` /
  `completed_at` field names; bulk import jobs sort by `created_at`.
- `POST /api/profiles` with an existing email → 409 with `errors[0].meta.duplicate_profile_id`.
- Merged source profile ids keep resolving right after a merge (merge is asynchronous).
- New segments report `is_processing = false` immediately but membership appears ~9 min later; `equals(is_starred,true)` is accepted
  but not applied; `name` filters on lists and segments allow only `any`/`equals`.

Metrics, events, reports
- Metric aggregates: any `sort` value → 400 (500 when combined with `by`); `page_size` minimum is 500; counts lag a few seconds
  behind `/api/events`. `equals(integration.category,"API")` returns nothing although metrics report that category
  (`integration.name` works). Flow value/series reports require `flow_message_id` next to `flow_id` in `group_by`.
- Malformed ids answer **400, not 404**: `GET /events/NOPE01`, `GET /flow-actions/NOPE01`, `GET /mapped-metrics/nope`, and any
  hyphenated coupon id — so `get()` throws instead of returning null there.

Campaigns, flows, templates, images
- `campaigns.list` needs `equals(messages.channel,…)` and allows only `contains` on `name`. `render_options` and image relationships
  are SMS/push-only. Recipient estimation: POST → 202, but job and estimation GETs stay 404 indefinitely on this account.
  `custom_tracking_params` must include `utm_source` and `utm_medium`. An SMS campaign draft is accepted without an SMS sender.
- Flows: `action_type` filter uses upper-snake names (`SEND_EMAIL`, `TIME_DELAY`, …) while definitions use kebab-case; date-filter
  bounds may not be in the future and `sort` must match the filtered field; a `send-email` action **copies the template into the
  flow**, and copy + flow message survive deletion of the flow (template delete → 409 forever).
- `PATCH /api/images/{id}` is a full replace: sending only `hidden` nulls `name`. Images cannot be deleted. `POST /api/images` rejects
  non-jpeg/png/gif URLs (data: URIs work); `greater-than(size,0)` → 400; hidden images are omitted unless `equals(hidden,true)`.
- Template html is normalised on write; `SYSTEM_DRAGGABLE` definitions need a non-empty `body.sections` and columns without `data`,
  contradicting the spec. `DELETE /api/template-universal-content/{id}` occasionally "resurrects" a previously PATCHed item.

Catalogs, coupons, forms, custom objects
- Catalog relationship POSTs are not idempotent (409 on re-adding a link); item bulk-create can stay `processing` 4+ min while items
  are already readable; `include=item` unsupported on `GET /catalog-variants/{id}`; catalog `sort` only `created`/`-created`;
  category filter field is `name`.
- Coupon `external_id` must match `^[0-9_A-z]+$` (same regex on path ids); `monitor_configuration.low_balance_threshold >= 100`.
- Forms: ≥2 steps per version; `properties.list_id` required on `submit: true` actions; no `id` members on create (string ids must be
  ULIDs); `location` only for `flyout`; `page[size]` is ignored on form-version relationship endpoints.
- Web-feed `name` must match `^[0-9_A-z]+$`; `status` is null until the first poll.
- Object types: filter only `equals(namespace,…)`; ingestion-log endpoints are cursor-only.

## 6. Upstream issues worth reporting to Klaviyo

1. `Klaviyo-Timestamp` webhook header carries local time labelled GMT (5 h off).
2. `GET /api/object-schemas/{unknown}/relationships/object-schemas`, `…/profile-object-schemas` and `POST …/profile-object-schemas`
   answer 500 for an unknown id (siblings 404).
3. `GET /api/forms/{id}/form-versions?filter=any(form_type,[…])` → 500 although the spec allows `any`.
4. Metric aggregates `sort` → 400 "unsupported operand type(s) for +" / 500 with `by`.
5. `PATCH /api/images/{id}` behaves as a full replace, contrary to JSON:API partial-update semantics.
6. `POST /campaign-message-assign-template` returns a different (flat) campaign-message shape than every other endpoint.
7. Campaign recipient estimation never completes; `equals(is_starred,true)` and `equals(integration.category,…)` filters ignored;
   `page[size]` ignored on form-version relationships.

## 7. Account residue the API cannot remove

- Images: ~22 uploads across runs (`sdk-smoke-<run>-img-*`), all `hidden=true`; no DELETE endpoint.
- Flow-owned template copies + their flow messages from each flows run (e.g. SfmZPb/WH8AuW, WJ5Qyd/T9e5xf, UfiU9T/SsT2Dq,
  WNWASx/V2wHn4, SxD2Yg/WKvQBR, X5MBXY/VhmJtv, RyjWHs/XyCDRw); invisible in `GET /templates`.
- Metrics `SDK Smoke <run>` from early metrics runs and one shared `SDK Smoke` metric (metrics are permanent); they also appear as
  webhook topics.
- Bulk-job records (catalog, coupon, profile import) expire after 7 days; raw records ingested into two deleted data sources expire
  after 14 days.
- Data-privacy deletion jobs were queued for every synthetic profile; the back-in-stock profile from the first catalogs run
  (`sdk-smoke+0904aec8-bis@…`) was already gone when the cleanup ran.
- One accident during the agent phase: a broad `contains(name,"sdk-smoke")` cleanup deleted a template belonging to a parallel run;
  the author brief now forbids broad-match deletes.
