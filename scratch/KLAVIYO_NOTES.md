# Klaviyo behaviour not derivable from the code

Observed 2026-09-04, revision 2026-07-15. Request rules live in the service/request docblocks; this is the remainder.

- Suppression jobs are XOR: profiles *or* `list_id` *or* `segment_id`.
- `equals(integration.category,"API")` on metrics returns nothing although metrics report that category; `integration.name` works.
- Catalog `sort` accepts only `created`/`-created`; category filter field is `name`.
- Web-feed `status` is null until the first poll.
- `SYSTEM_DRAGGABLE` template definitions need a non-empty `body.sections` and columns without `data`, contradicting the spec.
- `DELETE /api/template-universal-content/{id}` occasionally resurrects a previously PATCHed item.
- An SMS campaign draft is accepted without an SMS sender.
- Bulk subscribe without a list and without `historical_import` never creates a profile for a new email (no job-status endpoint to tell you).
- Pooling above an endpoint's burst tier is counter-productive: `/api/accounts` (1/s, 15/min) at concurrency 5 took 22–57 s for 10 requests. This is why `executePool()` retries one request at a time.
- Account entitlements seen on the test account: `POST /api/object-types` 403, `POST /api/push-tokens` 403, campaign recipient estimation never completes. Suites hitting these fail as "account limitation".

## Upstream issues worth reporting

1. `Klaviyo-Timestamp` webhook header carries local time labelled GMT (5 h off).
2. `GET /api/object-schemas/{unknown}/relationships/object-schemas`, `…/profile-object-schemas` and `POST …/profile-object-schemas` answer 500 for an unknown id (siblings 404).
3. `GET /api/forms/{id}/form-versions?filter=any(form_type,[…])` → 500 although the spec allows `any`.
4. Metric aggregates `sort` → 400 "unsupported operand type(s) for +" / 500 with `by`.
5. `PATCH /api/images/{id}` is a full replace, contrary to JSON:API partial-update semantics.
6. `POST /campaign-message-assign-template` returns a flat campaign-message shape unlike every other endpoint.
7. `equals(is_starred,true)` and `equals(integration.category,…)` filters ignored; `page[size]` ignored on form-version relationships.
