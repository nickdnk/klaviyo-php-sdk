<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCouponCodesJob;
use nickdnk\Klaviyo\Resources\Request\CreateCoupon;
use nickdnk\Klaviyo\Resources\Request\CreateCouponCode;
use nickdnk\Klaviyo\Resources\Request\UpdateCoupon;
use nickdnk\Klaviyo\Resources\Request\UpdateCouponCode;
use nickdnk\Klaviyo\Resources\Response\Coupon;
use nickdnk\Klaviyo\Resources\Response\CouponCode;
use nickdnk\Klaviyo\Resources\Response\CouponCodeBulkCreateJob;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for the coupon pair: `coupons` (the reusable definition) and `coupon-codes`
 * (the per-profile codes), including the coupon ↔ code relationships in both directions
 * and the `coupon-code-bulk-create-jobs` family with its hyphenated `coupon-codes`
 * attribute key.
 *
 * Responses come from the recorded fixtures in tests/fixtures/responses wherever one exists for
 * the operation, so the hydration assertions read back real Klaviyo payloads; the `FIXTURE_*`
 * ids below are the ids that corpus was recorded with. A coupon code's id is
 * `<coupon external id>-<unique code>`.
 */
class CouponServicesTest extends TestCase
{

    private const string FIXTURE_COUPON   = 'sdk_smoke_09048f53_coupon1';
    private const string FIXTURE_CODE_1   = 'sdk_smoke_09048f53_coupon1-sdk_smoke_09048f53_code1';
    private const string FIXTURE_CODE_2   = 'sdk_smoke_09048f53_coupon1-sdk_smoke_09048f53_code2';
    private const string FIXTURE_BULK_JOB = '01M1PAB7CT6X3ZS6R36792JN7Y';

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function path(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getUri()->getPath();

    }

    private static function query(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function body(MockHandler $mock): array
    {

        return json_decode((string)$mock->getLastRequest()->getBody(), true);

    }

    // region Coupons

    public function testCouponListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_coupons.200'),
            Fixtures::response('get_coupon.200'),
            Fixtures::response('create_coupon.201'),
            Fixtures::response('update_coupon.200'),
            Fixtures::response('delete_coupon.204'),
        ]);
        $coupons = self::client($mock)->coupons;

        $list = $coupons->list((new Query())->fields('coupon', 'external_id')->pageSize(50)->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupons', self::path($mock));
        self::assertSame([
            'fields' => ['coupon' => 'external_id'],
            'page'   => ['size' => '50', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(1, $list['data']);
        self::assertInstanceOf(Coupon::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_COUPON, $list['data'][0]->id);
        self::assertSame(self::FIXTURE_COUPON, $list['data'][0]->external_id);
        // Recorded with page[size]=1 against an account with more coupons, so this page has a next.
        self::assertSame(
            'https://a.klaviyo.com/api/coupons?fields%5Bcoupon%5D=external_id&page%5Bsize%5D=1&page%5Bcursor%5D=bmV4dDo6aWQ6OjIwODEwNDk',
            $list['links']->next
        );

        $coupon = $coupons->get('10OFF', (new Query())->fields('coupon', 'description'));
        self::assertSame('/api/coupons/10OFF', self::path($mock));
        self::assertSame(['fields' => ['coupon' => 'description']], self::query($mock));
        self::assertInstanceOf(Coupon::class, $coupon);
        self::assertSame(self::FIXTURE_COUPON, $coupon->external_id);

        $created = $coupons->create(new CreateCoupon('10OFF', 'Ten percent off'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupons', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'coupon',
                'attributes' => ['external_id' => '10OFF', 'description' => 'Ten percent off'],
            ],
        ], self::body($mock));
        self::assertInstanceOf(Coupon::class, $created);
        self::assertSame(self::FIXTURE_COUPON, $created->id);
        self::assertSame('SDK smoke coupon 1 — 10% off', $created->description);
        // Klaviyo echoes `low_balance_threshold` as a string, not a number.
        self::assertSame(['low_balance_threshold' => '500'], $created->monitor_configuration);

        $update = new UpdateCoupon('10OFF');
        $update->description = 'Now fifteen';
        $updated = $coupons->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupons/10OFF', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'coupon',
                'attributes' => ['description' => 'Now fifteen'],
                'id'         => '10OFF',
            ],
        ], self::body($mock));
        self::assertInstanceOf(Coupon::class, $updated);
        self::assertSame('SDK smoke coupon 1 — final description', $updated->description);
        self::assertSame(['low_balance_threshold' => '250'], $updated->monitor_configuration);

        self::assertNull($coupons->delete('10OFF'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupons/10OFF', self::path($mock));

    }

    public function testCouponCodeRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_coupon_codes_for_coupon.200'),
            Fixtures::response('get_coupon_code_ids_for_coupon.200'),
        ]);
        $coupons = self::client($mock)->coupons;

        $codes = $coupons->codes('10OFF', (new Query())->fields('coupon-code', 'unique_code')->filter(Filter::equals('status', 'UNASSIGNED')));
        self::assertSame('/api/coupons/10OFF/coupon-codes', self::path($mock));
        self::assertSame([
            'fields' => ['coupon-code' => 'unique_code'],
            'filter' => 'equals(status,"UNASSIGNED")',
        ], self::query($mock));
        self::assertInstanceOf(CouponCode::class, $codes['data'][0]);
        self::assertSame(self::FIXTURE_CODE_1, $codes['data'][0]->id);
        self::assertSame('sdk_smoke_09048f53_code1', $codes['data'][0]->unique_code);
        self::assertSame('UNASSIGNED', $codes['data'][0]->status);
        // The code carries its coupon as a to-one identifier and its profile as links only.
        self::assertSame(self::FIXTURE_COUPON, $codes['data'][0]->getRelationship('coupon')->data->id);
        self::assertFalse($codes['data'][0]->getRelationship('profile')->hasData);

        $ids = $coupons->codeIds('10OFF', (new Query())->pageSize(10));
        self::assertSame('/api/coupons/10OFF/relationships/coupon-codes', self::path($mock));
        self::assertSame(['page' => ['size' => '10']], self::query($mock));
        self::assertCount(1, $ids['data']);
        self::assertSame(self::FIXTURE_CODE_1, $ids['data'][0]->id);
        self::assertNull($ids['data'][0]->unique_code);
        self::assertStringContainsString('page%5Bcursor%5D=', $ids['links']->next);

    }

    // endregion

    // region Coupon codes

    public function testCouponCodeListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_coupon_codes.200'),
            Fixtures::response('get_coupon_code.200'),
            Fixtures::response('create_coupon_code.200'),
            Fixtures::response('update_coupon_code.200'),
            Fixtures::response('delete_coupon_code.204'),
        ]);
        $codes = self::client($mock)->couponCodes;

        $list = $codes->list((new Query())->filter(Filter::any('coupon.id', ['10OFF']))->include('coupon')->pageSize(25));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupon-codes', self::path($mock));
        self::assertSame([
            'filter'  => 'any(coupon.id,["10OFF"])',
            'include' => 'coupon',
            'page'    => ['size' => '25'],
        ], self::query($mock));
        self::assertCount(4, $list['data']);
        self::assertInstanceOf(CouponCode::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_CODE_1, $list['data'][0]->id);
        self::assertSame('2026-10-04T13:39:37+00:00', $list['data'][0]->expires_at);
        self::assertSame('2027-09-03T13:39:41+00:00', $list['data'][3]->expires_at);
        // `include=coupon`: the one included coupon is spliced into every code's relationship.
        self::assertInstanceOf(Coupon::class, $list['data'][0]->getRelationship('coupon')->data);
        self::assertSame(self::FIXTURE_COUPON, $list['data'][0]->getRelationship('coupon')->data->external_id);
        self::assertSame(self::FIXTURE_COUPON, $list['data'][3]->getRelationship('coupon')->data->external_id);
        self::assertCount(1, $list['included']);

        $code = $codes->get('cc1', (new Query())->include('coupon'));
        self::assertSame('/api/coupon-codes/cc1', self::path($mock));
        self::assertSame(['include' => 'coupon'], self::query($mock));
        self::assertInstanceOf(CouponCode::class, $code);
        self::assertSame('UNASSIGNED', $code->status);
        self::assertSame('sdk_smoke_09048f53_code1', $code->unique_code);
        self::assertSame('SDK smoke coupon 1 — final description', $code->getRelationship('coupon')->data->description);

        $created = $codes->create(new CreateCouponCode('XYZ789', '10OFF', '2026-12-31T00:00:00Z'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupon-codes', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'coupon-code',
                'attributes'    => ['unique_code' => 'XYZ789', 'expires_at' => '2026-12-31T00:00:00Z'],
                'relationships' => ['coupon' => ['data' => ['type' => 'coupon', 'id' => '10OFF']]],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CouponCode::class, $created);
        // A freshly created code is already UNASSIGNED, and its id is coupon id + unique code.
        self::assertSame(self::FIXTURE_CODE_2, $created->id);
        self::assertSame('sdk_smoke_09048f53_code2', $created->unique_code);
        self::assertSame('UNASSIGNED', $created->status);
        self::assertSame(self::FIXTURE_COUPON, $created->getRelationship('coupon')->data->id);

        $update = new UpdateCouponCode('cc1');
        $update->status = 'USED';
        $update->expires_at = '2027-01-01T00:00:00Z';
        $updated = $codes->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupon-codes/cc1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'coupon-code',
                'attributes' => ['status' => 'USED', 'expires_at' => '2027-01-01T00:00:00Z'],
                'id'         => 'cc1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(CouponCode::class, $updated);
        self::assertSame(self::FIXTURE_CODE_1, $updated->id);
        // The recorded PATCH only moved `expires_at`; the code was still unassigned.
        self::assertSame('UNASSIGNED', $updated->status);
        self::assertSame('2026-10-04T13:39:37+00:00', $updated->expires_at);

        self::assertNull($codes->delete('cc1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupon-codes/cc1', self::path($mock));

    }

    public function testCouponCodeCouponRelationship(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_coupon_for_coupon_code.200'),
            Fixtures::response('get_coupon_id_for_coupon_code.200'),
        ]);
        $codes = self::client($mock)->couponCodes;

        $coupon = $codes->coupon('cc1', (new Query())->fields('coupon', 'external_id'));
        self::assertSame('/api/coupon-codes/cc1/coupon', self::path($mock));
        self::assertSame(['fields' => ['coupon' => 'external_id']], self::query($mock));
        self::assertInstanceOf(Coupon::class, $coupon);
        self::assertSame(self::FIXTURE_COUPON, $coupon->id);
        self::assertSame('SDK smoke coupon 1 — final description', $coupon->description);

        $id = $codes->couponId('cc1');
        self::assertSame('/api/coupon-codes/cc1/relationships/coupon', self::path($mock));
        self::assertInstanceOf(Coupon::class, $id);
        self::assertSame(self::FIXTURE_COUPON, $id->id);
        self::assertNull($id->description);

    }

    // endregion

    // region Bulk create jobs

    public function testBulkCreateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_create_coupon_codes.202'),
            Fixtures::response('get_bulk_create_coupon_code_jobs.200'),
            Fixtures::response('get_bulk_create_coupon_codes_job.200'),
        ]);
        $codes = self::client($mock)->couponCodes;

        $job = $codes->bulkCreate(new BulkCreateCouponCodesJob([
            new CreateCouponCode('ABC123', '10OFF', '2026-12-31T00:00:00Z'),
            new CreateCouponCode('DEF456', 'FREESHIP'),
        ]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/coupon-code-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'coupon-code-bulk-create-job',
                'attributes' => [
                    'coupon-codes' => [
                        'data' => [
                            [
                                'type'          => 'coupon-code',
                                'attributes'    => ['unique_code' => 'ABC123', 'expires_at' => '2026-12-31T00:00:00Z'],
                                'relationships' => ['coupon' => ['data' => ['type' => 'coupon', 'id' => '10OFF']]],
                            ],
                            [
                                'type'          => 'coupon-code',
                                'attributes'    => ['unique_code' => 'DEF456'],
                                'relationships' => ['coupon' => ['data' => ['type' => 'coupon', 'id' => 'FREESHIP']]],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CouponCodeBulkCreateJob::class, $job);
        self::assertSame(self::FIXTURE_BULK_JOB, $job->id);
        // The 202 already reports `processing` — Klaviyo never hands back a `queued` job here.
        self::assertSame('processing', $job->status);
        self::assertSame(5, $job->total_count);
        self::assertSame(0, $job->completed_count);
        // The 202 carries the `coupon-codes` relationship as links only.
        self::assertFalse($job->getRelationship('coupon-codes')->hasData);

        $jobs = $codes->getBulkCreateJobs((new Query())->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('/api/coupon-code-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CouponCodeBulkCreateJob::class, $jobs['data'][0]);
        self::assertCount(3, $jobs['data']);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(5, $jobs['data'][0]->completed_count);
        self::assertCount(5, $jobs['data'][0]->getRelationship('coupon-codes')->ids());
        self::assertSame(
            'sdk_smoke_09048f53_coupon2-sdk_smoke_09048f53_bulk1',
            $jobs['data'][0]->getRelationship('coupon-codes')->ids()[0]
        );

        $fetched = $codes->getBulkCreateJob('j1', (new Query())->include('coupon-codes')->fields('coupon-code', 'unique_code'));
        self::assertSame('/api/coupon-code-bulk-create-jobs/j1', self::path($mock));
        self::assertSame([
            'fields'  => ['coupon-code' => 'unique_code'],
            'include' => 'coupon-codes',
        ], self::query($mock));
        self::assertInstanceOf(CouponCodeBulkCreateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(5, $fetched->total_count);
        self::assertSame(0, $fetched->completed_count);
        self::assertSame([], $fetched->errors);
        // `include=coupon-codes` on a still-processing job returns the relationship with empty `data`.
        self::assertSame([], $fetched->getRelationship('coupon-codes')->data);

    }

    // endregion

}
