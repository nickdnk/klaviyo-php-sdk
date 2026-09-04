<?php
/**
 * CouponService + CouponCodeService, end to end: create coupons (external_id, description,
 * monitor_configuration) → read with every query knob the spec lists → codes created singly and
 * through a bulk-create job (polled to completion) → relationship traversal both ways → update →
 * delete everything. Touches nothing but the coupons/codes it creates (sdk-smoke-<run>-*).
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCouponCodesJob;
use nickdnk\Klaviyo\Resources\Request\CreateCoupon;
use nickdnk\Klaviyo\Resources\Request\CreateCouponCode;
use nickdnk\Klaviyo\Resources\Request\UpdateCoupon;
use nickdnk\Klaviyo\Resources\Request\UpdateCouponCode;
use nickdnk\Klaviyo\Resources\Response\Coupon;
use nickdnk\Klaviyo\Resources\Response\CouponCode;
use nickdnk\Klaviyo\Resources\Response\CouponCodeBulkCreateJob;
use Smoke\Harness;

return function (Harness $h): void {

    // Klaviyo constrains coupon external_ids to ^[0-9_A-z]+$ — no hyphens allowed — so the
    // harness name is translated to underscores. Coupon code unique_codes get the same treatment.
    $n = fn(string $what) => str_replace('-', '_', $h->name($what));
    $h->note('Coupon external_id (and therefore the coupon id) must match ^[0-9_A-z]+$; the hyphens in Harness::name() are rejected with a 400, so this suite substitutes underscores. The same regex is applied to the {id} path parameter of GET/PATCH/DELETE /api/coupons/{id}, so a hyphenated unknown id answers 400 rather than 404.');
    $h->note('monitor_configuration.low_balance_threshold must be >= 100 (400 "must be greater than or equal to 100" otherwise); the OpenAPI spec types monitor_configuration as a free-form object and documents no bounds.');

    $expires = (new DateTimeImmutable('+30 days'))->setTimezone(new DateTimeZone('UTC'));
    $expiresAt = $expires->format(DateTimeInterface::ATOM);
    $expiresLater = (new DateTimeImmutable('+45 days'))->setTimezone(new DateTimeZone('UTC'));

    // ───────────────────────────── Coupons ─────────────────────────────

    /** @var Coupon|null $c1 */
    $c1 = $h->step('coupons', 'create', 'create coupon (external_id + description)',
        fn(APIClient $c) => $c->coupons->create(new CreateCoupon($n('coupon1'), 'SDK smoke coupon 1 — 10% off')),
        function ($r) use ($h, $n) {
            $h->assert($r instanceof Coupon, 'Coupon instance');
            $h->assert($r->id === $n('coupon1'), 'coupon id equals external_id');
            $h->assert($r->external_id === $n('coupon1'), 'external_id echoed');
            $h->assert($r->description === 'SDK smoke coupon 1 — 10% off', 'description echoed');
        });

    if ($c1) {
        $h->cleanup('coupons', 'delete', 'coupon1', fn(APIClient $c) => $c->coupons->delete($c1->id));
    }

    /** @var Coupon|null $c2 */
    $c2 = $h->step('coupons', 'create', 'create coupon (external_id + description + monitor_configuration) + fields[coupon]',
        function (APIClient $c) use ($h, $n) {
            $coupon = new CreateCoupon($n('coupon2'), 'SDK smoke coupon 2 — bulk codes');
            $coupon->monitor_configuration = ['low_balance_threshold' => 500];
            return $c->coupons->create($coupon, (new Query())->fields('coupon', 'external_id', 'description', 'monitor_configuration'));
        },
        function ($r) use ($h, $n) {
            $h->assert($r instanceof Coupon && $r->id === $n('coupon2'), 'coupon2 created');
            $h->assert($r->monitor_configuration !== null, 'monitor_configuration hydrated');
        });

    if ($c2) {
        $h->cleanup('coupons', 'delete', 'coupon2', fn(APIClient $c) => $c->coupons->delete($c2->id));
    }

    if (!$c1) {
        $h->note('Cannot continue without a coupon; the remaining coupon-code steps all hang off one.');
        return;
    }

    $h->step('coupons', 'get', 'get coupon w/ fields[coupon]=id,external_id,description,monitor_configuration',
        fn(APIClient $c) => $c->coupons->get($c1->id, (new Query())->fields('coupon', 'id', 'external_id', 'description', 'monitor_configuration')),
        fn($r) => $h->assert($r instanceof Coupon && $r->id === $c1->id && $r->external_id === $c1->id, 'sparse fieldset hydrated'));

    $h->step('coupons', 'get', 'get coupon w/ fields[coupon]=external_id only (description omitted)',
        fn(APIClient $c) => $c->coupons->get($c1->id, (new Query())->fields('coupon', 'external_id')),
        fn($r) => $h->assert($r instanceof Coupon && $r->external_id === $c1->id && $r->description === null, 'only external_id returned'));

    $h->step('coupons', 'get', 'get unknown coupon → null',
        fn(APIClient $c) => $c->coupons->get($n('nope')),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('coupons', 'list', 'list coupons w/ fields[coupon] + page[size]=100 (spec max)',
        fn(APIClient $c) => $c->coupons->list((new Query())->fields('coupon', 'external_id', 'description')->pageSize(100)),
        function ($r) use ($h, $c1) {
            $h->assert(is_array($r['data']) && array_key_exists('links', $r), 'list envelope');
            $h->assert($r['data'] === [] || $r['data'][0] instanceof Coupon, 'typed rows');
            $h->assert(in_array($c1->id, array_map(fn(Coupon $x) => $x->id, $r['data']), true), 'our coupon is listed');
        });

    $h->step('coupons', 'list', 'paginate coupons via links.next + Query::cursor(url) (page[size]=1)', function (APIClient $c) use ($h) {
        $first = $c->coupons->list((new Query())->pageSize(1)->fields('coupon', 'external_id'));
        $h->assert(count($first['data']) === 1, 'first page has exactly 1');
        $h->assert($first['links']?->next !== null, 'links.next present (>=2 coupons on the account)');
        $second = $c->coupons->list(next: $first['links']->next);
        $h->assert(count($second['data']) === 1 && $second['data'][0]->id !== $first['data'][0]->id, 'second page differs');
        $viaCursor = $c->coupons->list((new Query())->pageSize(1)->fields('coupon', 'external_id')->cursor($first['links']->next));
        $h->assert($viaCursor['data'][0]->id === $second['data'][0]->id, 'Query::cursor(url) == next:');
        return $second;
    });

    $h->step('coupons', 'update', 'update coupon description + monitor_configuration + fields[coupon]',
        function (APIClient $c) use ($h, $c1) {
            $u = new UpdateCoupon($c1->id);
            $u->description = 'SDK smoke coupon 1 — updated description';
            $u->monitor_configuration = ['low_balance_threshold' => 250]; // Klaviyo rejects < 100
            return $c->coupons->update($u, (new Query())->fields('coupon', 'description', 'monitor_configuration', 'external_id'));
        },
        function ($r) use ($h, $c1) {
            $h->assert($r instanceof Coupon && $r->id === $c1->id, 'same coupon back');
            $h->assert($r->description === 'SDK smoke coupon 1 — updated description', 'description updated');
        });

    $h->step('coupons', 'update', 'update coupon description only (monitor_configuration untouched)',
        function (APIClient $c) use ($c1) {
            $u = new UpdateCoupon($c1->id);
            $u->description = 'SDK smoke coupon 1 — final description';
            return $c->coupons->update($u);
        },
        fn($r) => $h->assert($r instanceof Coupon && $r->description === 'SDK smoke coupon 1 — final description', 'second update applied'));

    $h->step('coupons', 'executePool', 'executePool: returnRequest get of both coupons concurrently',
        function (APIClient $c) use ($h, $c1, $c2) {
            $requests = [$c->coupons->get($c1->id, (new Query())->fields('coupon', 'external_id'), returnRequest: true)];
            if ($c2) {
                $requests[] = $c->coupons->get($c2->id, returnRequest: true);
            }
            $res = $c->executePool($requests, 2);
            APIClient::assertNoExceptions($res);
            $h->assert(count($res) === count($requests) && $res[0] instanceof Coupon, 'pooled results hydrated');
            return $res;
        });

    // ───────────────────────── Coupon codes (single) ─────────────────────────

    $codes = [];
    foreach (['code1', 'code2', 'code3'] as $i => $tag) {
        /** @var CouponCode|null $code */
        $code = $h->step('couponCodes', 'create', "create coupon code {$tag} (unique_code + coupon relationship + expires_at" . ($i === 0 ? ' + fields[coupon-code]' : '') . ')',
            fn(APIClient $c) => $c->couponCodes->create(
                new CreateCouponCode($n($tag), $c1->id, $expiresAt),
                $i === 0 ? (new Query())->fields('coupon-code', 'unique_code', 'expires_at', 'status') : null
            ),
            function ($r) use ($h, $n, $tag, $c1) {
                $h->assert($r instanceof CouponCode && $r->id !== null, 'CouponCode with id');
                $h->assert($r->unique_code === $n($tag), 'unique_code echoed');
                $h->assert($r->id === $c1->id . '-' . $n($tag), 'id is <couponId>-<unique_code>');
                $h->assert($r->status !== null, 'status hydrated');
            });
        if ($code) {
            $codes[] = $code;
            $h->cleanup('couponCodes', 'delete', "coupon code {$tag}", fn(APIClient $c) => $c->couponCodes->delete($code->id));
        }
    }

    $h->step('couponCodes', 'create', 'create coupon code without expires_at (Klaviyo defaults to +1 year)',
        function (APIClient $c) use ($h, $n, $c1, &$codes) {
            $r = $c->couponCodes->create(new CreateCouponCode($n('code4'), $c1->id));
            $codes[] = $r;
            return $r;
        },
        fn($r) => $h->assert($r instanceof CouponCode && $r->expires_at !== null, 'expires_at defaulted by the API'));

    if (isset($codes[3])) {
        $code4 = $codes[3];
        $h->cleanup('couponCodes', 'delete', 'coupon code code4', fn(APIClient $c) => $c->couponCodes->delete($code4->id));
    }

    /** @var CouponCode|null $first */
    $first = $codes[0] ?? null;

    if ($first) {
        $h->step('couponCodes', 'get', 'get coupon code w/ fields[coupon-code] + fields[coupon] + include(coupon)',
            fn(APIClient $c) => $c->couponCodes->get($first->id, (new Query())
                ->fields('coupon-code', 'unique_code', 'expires_at', 'status')
                ->fields('coupon', 'external_id', 'description')
                ->include('coupon')),
            function ($r) use ($h, $first, $c1) {
                $h->assert($r instanceof CouponCode && $r->id === $first->id, 'same code back');
                $h->assert($r->unique_code === $first->unique_code, 'unique_code');
                $h->assert($r->getRelationship('coupon')?->data?->id === $c1->id, 'coupon relationship id hydrated');
            });

        $h->step('couponCodes', 'get', 'get coupon code w/ fields[coupon-code]=status only',
            fn(APIClient $c) => $c->couponCodes->get($first->id, (new Query())->fields('coupon-code', 'status')),
            fn($r) => $h->assert($r instanceof CouponCode && $r->status !== null && $r->unique_code === null, 'sparse fieldset respected'));

        $h->step('couponCodes', 'coupon', 'coupon for coupon code w/ fields[coupon]',
            fn(APIClient $c) => $c->couponCodes->coupon($first->id, (new Query())->fields('coupon', 'external_id', 'description')),
            fn($r) => $h->assert($r instanceof Coupon && $r->id === $c1->id && $r->external_id === $c1->id, 'our coupon, hydrated'));

        $h->step('couponCodes', 'couponId', 'coupon id for coupon code (identifier only)',
            fn(APIClient $c) => $c->couponCodes->couponId($first->id),
            fn($r) => $h->assert($r instanceof Coupon && $r->id === $c1->id && $r->external_id === null, 'id only'));

        $h->step('couponCodes', 'update', 'update coupon code expires_at + fields[coupon-code]',
            function (APIClient $c) use ($first, $expiresLater) {
                $u = new UpdateCouponCode($first->id);
                $u->expires_at = $expiresLater->format(DateTimeInterface::ATOM);
                return $c->couponCodes->update($u, (new Query())->fields('coupon-code', 'expires_at', 'status', 'unique_code'));
            },
            function ($r) use ($h, $first, $expiresLater) {
                $h->assert($r instanceof CouponCode && $r->id === $first->id, 'same code');
                $h->assert($r->expires_at !== null && (new DateTimeImmutable($r->expires_at))->format('Y-m-d') === $expiresLater->format('Y-m-d'), 'expires_at moved to +45d');
            });

        $h->step('couponCodes', 'update', 'update coupon code status=UNASSIGNED + expires_at',
            function (APIClient $c) use ($first, $expires) {
                $u = new UpdateCouponCode($first->id);
                $u->status = 'UNASSIGNED';
                $u->expires_at = $expires->format(DateTimeInterface::ATOM);
                return $c->couponCodes->update($u);
            },
            fn($r) => $h->assert($r instanceof CouponCode && $r->status === 'UNASSIGNED', 'status echoed as UNASSIGNED'));
    }

    $h->step('couponCodes', 'get', 'get unknown coupon code → null',
        fn(APIClient $c) => $c->couponCodes->get($c1->id . '-sdk-smoke-nope'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('couponCodes', 'coupon', 'coupon for unknown coupon code → null',
        fn(APIClient $c) => $c->couponCodes->coupon($c1->id . '-sdk-smoke-nope'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('couponCodes', 'couponId', 'coupon id for unknown coupon code → null',
        fn(APIClient $c) => $c->couponCodes->couponId($c1->id . '-sdk-smoke-nope'),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────── Coupon codes: listing (filter is required) ─────────────────────

    $listed = $h->step('couponCodes', 'list', 'list w/ REQUIRED filter equals(coupon.id) + fields both types + include(coupon) + page[size]=100 (poll until visible)',
        fn(APIClient $c) => $h->waitFor(function () use ($c, $h, $c1, $codes) {
            $r = $c->couponCodes->list((new Query())
                ->filter(Filter::equals('coupon.id', $c1->id))
                ->fields('coupon-code', 'unique_code', 'expires_at', 'status')
                ->fields('coupon', 'external_id')
                ->include('coupon')
                ->pageSize(100));
            return count($r['data']) >= count($codes) ? $r : null;
        }, 120, 5, 'single coupon codes to become listable'),
        function ($r) use ($h, $c1, $codes) {
            $h->assert(count($r['data']) >= count($codes), 'all our codes listed');
            $h->assert($r['data'][0] instanceof CouponCode && $r['data'][0]->unique_code !== null, 'typed + hydrated');
            $h->assert($r['data'][0]->getRelationship('coupon')?->data?->id === $c1->id, 'include(coupon) → relationship hydrated');
        });

    $h->step('couponCodes', 'list', 'list w/ filter any(coupon.id,[c1,c2]) + equals(status,"UNASSIGNED")',
        fn(APIClient $c) => $c->couponCodes->list((new Query())
            ->filter(Filter::all(
                Filter::any('coupon.id', array_values(array_filter([$c1->id, $c2?->id]))),
                Filter::equals('status', 'UNASSIGNED'),
            ))
            ->fields('coupon-code', 'unique_code', 'status')
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) >= 1 && array_reduce($r['data'], fn($ok, CouponCode $x) => $ok && $x->status === 'UNASSIGNED', true), 'all UNASSIGNED'));

    $h->step('couponCodes', 'list', 'list w/ filter equals(coupon.id) + expires_at range (greater-than now, less-than +2y)',
        fn(APIClient $c) => $c->couponCodes->list((new Query())
            ->filter(Filter::all(
                Filter::equals('coupon.id', $c1->id),
                Filter::greaterThan('expires_at', new DateTimeImmutable('now')),
                Filter::lessThan('expires_at', new DateTimeImmutable('+2 years')),
            ))
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) >= 1, 'date-range filter returns our codes'));

    $h->step('couponCodes', 'list', 'paginate coupon codes via links.next + Query::cursor(url) (page[size]=1)', function (APIClient $c) use ($h, $c1) {
        $q = fn() => (new Query())->filter(Filter::equals('coupon.id', $c1->id))->pageSize(1);
        $p1 = $c->couponCodes->list($q());
        $h->assert(count($p1['data']) === 1, 'page 1 has 1');
        $h->assert($p1['links']?->next !== null, 'links.next present');
        $p2 = $c->couponCodes->list(next: $p1['links']->next);
        $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
        $viaCursor = $c->couponCodes->list($q()->cursor($p1['links']->next));
        $h->assert($viaCursor['data'][0]->id === $p2['data'][0]->id, 'Query::cursor(url) == next:');
        return $p2;
    });

    $h->step('couponCodes', 'list', 'list w/ filter equals(coupon.id) on a coupon with no codes → empty',
        fn(APIClient $c) => $c2
            ? $c->couponCodes->list((new Query())->filter(Filter::equals('coupon.id', $c2->id))->pageSize(100))
            : throw new RuntimeException('coupon2 was not created'),
        fn($r) => $h->assert(is_array($r['data']), 'array (may be empty before bulk job runs)'));

    $h->step('couponCodes', 'executePool', 'executePool: returnRequest get of every single-created code',
        function (APIClient $c) use ($h, $codes) {
            $res = $c->executePool(array_map(fn(CouponCode $x) => $c->couponCodes->get($x->id, returnRequest: true), $codes), 3);
            APIClient::assertNoExceptions($res);
            $h->assert(count($res) === count($codes) && $res[0] instanceof CouponCode, 'all hydrated');
            return $res;
        });

    // ───────────────── Coupon codes: relationship endpoints on the coupon ─────────────────

    $h->step('coupons', 'codes', 'codes for coupon w/ fields[coupon-code] + page[size]=100',
        fn(APIClient $c) => $c->coupons->codes($c1->id, (new Query())->fields('coupon-code', 'unique_code', 'expires_at', 'status')->pageSize(100)),
        function ($r) use ($h, $codes) {
            $h->assert(count($r['data']) >= count($codes), 'our codes come back');
            $h->assert($r['data'][0] instanceof CouponCode && $r['data'][0]->unique_code !== null, 'typed + hydrated');
        });

    $h->step('coupons', 'codes', 'codes for coupon w/ filter equals(status,"UNASSIGNED") + greater-than(expires_at,now)',
        fn(APIClient $c) => $c->coupons->codes($c1->id, (new Query())
            ->filter(Filter::all(
                Filter::equals('status', 'UNASSIGNED'),
                Filter::greaterThan('expires_at', new DateTimeImmutable('now')),
            ))
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) >= 1, 'filtered codes'));

    $h->step('coupons', 'codes', 'paginate coupon.codes via links.next (page[size]=1)', function (APIClient $c) use ($h, $c1) {
        $p1 = $c->coupons->codes($c1->id, (new Query())->pageSize(1));
        $h->assert(count($p1['data']) === 1, 'page 1 has 1');
        $h->assert($p1['links']?->next !== null, 'links.next present');
        $p2 = $c->coupons->codes($c1->id, next: $p1['links']->next);
        $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
        return $p2;
    });

    $h->step('coupons', 'codeIds', 'code ids for coupon w/ page[size]=100',
        fn(APIClient $c) => $c->coupons->codeIds($c1->id, (new Query())->pageSize(100)),
        function ($r) use ($h, $codes) {
            $h->assert(count($r['data']) >= count($codes), 'ids for our codes');
            $h->assert($r['data'][0] instanceof CouponCode && $r['data'][0]->id !== null && $r['data'][0]->unique_code === null, 'identifiers only');
        });

    $h->step('coupons', 'codeIds', 'code ids for coupon w/ filter equals(status,"UNASSIGNED")',
        fn(APIClient $c) => $c->coupons->codeIds($c1->id, (new Query())->filter(Filter::equals('status', 'UNASSIGNED'))->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) >= 1, 'filtered ids'));

    $h->step('coupons', 'codeIds', 'paginate coupon.codeIds via links.next (page[size]=1)', function (APIClient $c) use ($h, $c1) {
        $p1 = $c->coupons->codeIds($c1->id, (new Query())->pageSize(1));
        $h->assert(count($p1['data']) === 1, 'page 1 has 1');
        $h->assert($p1['links']?->next !== null, 'links.next present');
        $p2 = $c->coupons->codeIds($c1->id, next: $p1['links']->next);
        $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
        return $p2;
    });

    // ───────────────────────── Bulk create job ─────────────────────────

    $bulkTags = ['bulk1', 'bulk2', 'bulk3', 'bulk4', 'bulk5'];
    $bulkCouponId = $c2?->id ?? $c1->id;

    /** @var CouponCodeBulkCreateJob|null $job */
    $job = $h->step('couponCodes', 'bulkCreate', 'bulk create 5 coupon codes (one job, expires_at on each)',
        fn(APIClient $c) => $c->couponCodes->bulkCreate(new BulkCreateCouponCodesJob(
            array_map(fn(string $t) => new CreateCouponCode($n($t), $bulkCouponId, $expiresAt), $bulkTags)
        )),
        function ($r) use ($h) {
            $h->assert($r instanceof CouponCodeBulkCreateJob && $r->id !== null, 'job resource with id');
            $h->assert($r->status !== null, 'status hydrated');
            $h->assert($r->total_count === null || $r->total_count === 5, 'total_count 5 when present');
        });

    if ($job) {
        $done = $h->step('couponCodes', 'getBulkCreateJob', 'poll bulk job w/ fields[job] + fields[coupon-code] + include(coupon-codes) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $job) {
                $j = $c->couponCodes->getBulkCreateJob($job->id, (new Query())
                    ->fields('coupon-code-bulk-create-job', 'status', 'created_at', 'completed_at', 'total_count', 'completed_count', 'failed_count', 'errors', 'expires_at')
                    ->fields('coupon-code', 'unique_code', 'status', 'expires_at')
                    ->include('coupon-codes'));
                return in_array($j?->status, ['complete', 'cancelled'], true) ? $j : null;
            }, 180, 5, 'bulk create job to finish'),
            function ($r) use ($h) {
                $h->assert($r instanceof CouponCodeBulkCreateJob && $r->status === 'complete', 'job completed');
                $h->assert($r->total_count === 5, 'total_count 5');
                $h->assert($r->completed_count === 5, 'completed_count 5');
                $h->assert((int)$r->failed_count === 0, 'no failures');
                $h->assert($r->getRelationship('coupon-codes') !== null, 'include(coupon-codes) → relationship hydrated');
            });

        $h->step('couponCodes', 'getBulkCreateJob', 'get bulk job w/ fields[job]=status only (no include)',
            fn(APIClient $c) => $c->couponCodes->getBulkCreateJob($job->id, (new Query())->fields('coupon-code-bulk-create-job', 'status')),
            fn($r) => $h->assert($r instanceof CouponCodeBulkCreateJob && $r->status !== null && $r->created_at === null, 'sparse fieldset respected'));

        if ($done && $done->status === 'complete') {
            $ids = array_map(fn($x) => $x->id, (array)($done->getRelationship('coupon-codes')?->data ?? []));
            if (!$ids) {
                $ids = array_map(fn(string $t) => $bulkCouponId . '-' . $n($t), $bulkTags);
            }
            foreach ($ids as $i => $bulkId) {
                $h->cleanup('couponCodes', 'delete', "bulk coupon code #{$i}", fn(APIClient $c) => $c->couponCodes->delete($bulkId));
            }

            $h->step('couponCodes', 'list', 'list bulk-created codes: filter equals(coupon.id,coupon2) + include(coupon) (poll)',
                fn(APIClient $c) => $h->waitFor(function () use ($c, $bulkCouponId) {
                    $r = $c->couponCodes->list((new Query())
                        ->filter(Filter::equals('coupon.id', $bulkCouponId))
                        ->fields('coupon-code', 'unique_code', 'status')
                        ->include('coupon')
                        ->pageSize(100));
                    return count($r['data']) >= 5 ? $r : null;
                }, 180, 5, 'bulk codes to become listable'),
                fn($r) => $h->assert(count($r['data']) >= 5, 'all 5 bulk codes listed'));
        }
    }

    $h->step('couponCodes', 'getBulkCreateJobs', 'list bulk jobs w/ filter equals(status,"complete") + fields[job]',
        fn(APIClient $c) => $c->couponCodes->getBulkCreateJobs((new Query())
            ->filter(Filter::equals('status', 'complete'))
            ->fields('coupon-code-bulk-create-job', 'status', 'total_count', 'completed_count', 'created_at')),
        function ($r) use ($h, $job) {
            $h->assert(is_array($r['data']) && array_key_exists('links', $r), 'list envelope');
            $h->assert($r['data'] === [] || $r['data'][0] instanceof CouponCodeBulkCreateJob, 'typed rows');
            if ($job) {
                $h->assert(in_array($job->id, array_map(fn($x) => $x->id, $r['data']), true), 'our completed job is listed');
            }
        });

    $h->step('couponCodes', 'getBulkCreateJobs', 'list bulk jobs w/ filter equals(status,"queued") (usually empty)',
        fn(APIClient $c) => $c->couponCodes->getBulkCreateJobs((new Query())->filter(Filter::equals('status', 'queued'))),
        fn($r) => $h->assert(is_array($r['data']), 'array'));

    $h->step('couponCodes', 'getBulkCreateJobs', 'paginate bulk jobs via links.next / Query::cursor', function (APIClient $c) use ($h) {
        $p1 = $c->couponCodes->getBulkCreateJobs();
        $h->assert(is_array($p1['data']), 'first page');
        if ($p1['links']?->next === null) {
            $h->note('coupon-code-bulk-create-jobs: only one page on this account, so links.next pagination could not be walked (the endpoint takes no page[size]).');
            return $p1;
        }
        $p2 = $c->couponCodes->getBulkCreateJobs(next: $p1['links']->next);
        $h->assert($p2['data'] === [] || $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');
        return $p2;
    });

    $h->step('couponCodes', 'getBulkCreateJob', 'get unknown bulk job → null',
        fn(APIClient $c) => $c->couponCodes->getBulkCreateJob('01GSQPBF74KQ5YTDEPP41T1BZH'),
        fn($r) => $h->assert($r === null, 'null on 404'));
};
