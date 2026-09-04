<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogCategory;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItem as ResponseCatalogItem;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for `catalog-categories`: the five resource operations, the `items`
 * relationship trio, and all three bulk-job families (`catalog-category-bulk-create-jobs`,
 * `-update-jobs`, `-delete-jobs`) with their `categories: {data: […]}` attribute.
 *
 * Category and item ids are composite — integration type, catalog type and external id
 * joined by `:::`, e.g. `$custom:::$default:::SAMPLE-DATA-CATEGORY-1`. Both `$` and `:`
 * are legal path characters, so the ids reach the URI path unescaped; the path assertions
 * pin that.
 *
 * Responses come from the recorded fixtures in tests/fixtures/responses wherever one exists for
 * the operation, so the hydration assertions read back real Klaviyo payloads; the `FIXTURE_*`
 * ids below are the composite ids that corpus was recorded with.
 */
class CatalogCategoryServiceTest extends TestCase
{

    private const string CATEGORY_ID = '$custom:::$default:::SAMPLE-DATA-CATEGORY-1';
    private const string ITEM_ID     = '$custom:::$default:::SAMPLE-DATA-ITEM-1';

    private const string FIXTURE_CATEGORY_1 = '$custom:::$default:::sdk-smoke-090444b5-cat1';
    private const string FIXTURE_CATEGORY_2 = '$custom:::$default:::sdk-smoke-090444b5-cat2';
    private const string FIXTURE_ITEM_1     = '$custom:::$default:::sdk-smoke-090444b5-item1';
    private const string FIXTURE_ITEM_2     = '$custom:::$default:::sdk-smoke-090444b5-item2';
    private const string FIXTURE_BULK_CAT_1 = '$custom:::$default:::sdk-smoke-090444b5-bcat1';
    private const string FIXTURE_BULK_CAT_2 = '$custom:::$default:::sdk-smoke-090444b5-bcat2';

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function method(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getMethod();

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

    // region Categories

    public function testCategoryListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_catalog_categories.200'),
            Fixtures::response('get_catalog_category.200'),
            Fixtures::response('create_catalog_category.201'),
            Fixtures::response('update_catalog_category.200'),
            Fixtures::response('delete_catalog_category.204'),
        ]);
        $categories = self::client($mock)->catalogCategories;

        $list = $categories->list((new Query())->fields('catalog-category', 'name')->filter(Filter::equals('name', 'Summer'))->sort('created', true)->pageSize(50)->cursor('cur1'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/catalog-categories', self::path($mock));
        self::assertSame([
            'fields' => ['catalog-category' => 'name'],
            'filter' => 'equals(name,"Summer")',
            'sort'   => '-created',
            'page'   => ['size' => '50', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(1, $list['data']);
        self::assertInstanceOf(CatalogCategory::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_CATEGORY_1, $list['data'][0]->id);
        self::assertSame('SDK Smoke Cat One 090444b5', $list['data'][0]->name);
        self::assertSame('sdk-smoke-090444b5-cat1', $list['data'][0]->external_id);
        self::assertSame('2026-09-04T13:38:44.628000+00:00', $list['data'][0]->updated);
        // A category list never inlines `items`, only the relationship's links.
        self::assertFalse($list['data'][0]->getRelationship('items')->hasData);
        self::assertNull($list['links']->next);

        $category = $categories->get(self::CATEGORY_ID, (new Query())->fields('catalog-category', 'name', 'external_id'));
        self::assertSame('/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1', self::path($mock));
        self::assertSame(['fields' => ['catalog-category' => 'name,external_id']], self::query($mock));
        self::assertInstanceOf(CatalogCategory::class, $category);
        self::assertSame('$custom:::$default:::sdk-smoke-090444b5-bcat2', $category->id);
        self::assertSame('SDK Smoke Bulk Cat UPDATED sdk-smoke-090444b5-bcat2', $category->name);

        $created = $categories->create(new CreateCatalogCategory('SUMMER', 'Summer', itemIds: [self::ITEM_ID]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/catalog-categories', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'catalog-category',
                'attributes'    => [
                    'external_id'      => 'SUMMER',
                    'name'             => 'Summer',
                    'integration_type' => '$custom',
                    'catalog_type'     => '$default',
                ],
                'relationships' => [
                    'items' => ['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategory::class, $created);
        self::assertSame(self::FIXTURE_CATEGORY_2, $created->id);
        self::assertSame('sdk-smoke-090444b5-cat2', $created->external_id);
        self::assertSame('SDK Smoke Cat Two 090444b5', $created->name);

        $update = new UpdateCatalogCategory(self::CATEGORY_ID, [self::ITEM_ID]);
        $update->name = 'Renamed Category';
        $updated = $categories->update($update, (new Query())->fields('catalog-category', 'name'));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame('/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1', self::path($mock));
        self::assertSame(['fields' => ['catalog-category' => 'name']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'          => 'catalog-category',
                'attributes'    => ['name' => 'Renamed Category'],
                'relationships' => [
                    'items' => ['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]],
                ],
                'id'            => self::CATEGORY_ID,
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategory::class, $updated);
        self::assertSame(self::FIXTURE_CATEGORY_1, $updated->id);
        self::assertSame('SDK Smoke Cat One RENAMED 090444b5', $updated->name);
        self::assertSame('2026-09-04T13:38:59.717000+00:00', $updated->updated);

        self::assertNull($categories->delete(self::CATEGORY_ID));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame('/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1', self::path($mock));

    }

    /**
     * A category created without an `items` relationship sends attributes only, and an
     * explicit integration / catalog type overrides the `$custom` / `$default` defaults.
     */
    public function testCategoryCreateWithoutItems(): void
    {

        $mock = new MockHandler([Fixtures::response('create_catalog_category.201')]);
        $categories = self::client($mock)->catalogCategories;

        $created = $categories->create(new CreateCatalogCategory('WINTER', 'Winter', '$custom', '$default'));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/catalog-categories', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-category',
                'attributes' => [
                    'external_id'      => 'WINTER',
                    'name'             => 'Winter',
                    'integration_type' => '$custom',
                    'catalog_type'     => '$default',
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategory::class, $created);
        self::assertSame('sdk-smoke-090444b5-cat2', $created->external_id);

    }

    // endregion

    // region Item relationships

    public function testItemRelationshipReads(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_items_for_catalog_category.200'),
            Fixtures::response('get_item_ids_for_catalog_category.200'),
        ]);
        $categories = self::client($mock)->catalogCategories;

        $items = $categories->items(self::CATEGORY_ID, (new Query())->fields('catalog-item', 'title')->include('variants')->sort('created')->pageSize(20));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1/items', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-item' => 'title'],
            'include' => 'variants',
            'sort'    => 'created',
            'page'    => ['size' => '20'],
        ], self::query($mock));
        self::assertCount(2, $items['data']);
        self::assertInstanceOf(ResponseCatalogItem::class, $items['data'][0]);
        self::assertSame(self::FIXTURE_ITEM_1, $items['data'][0]->id);
        self::assertSame('SDK Smoke Item One 090444b5', $items['data'][0]->title);
        self::assertSame(19.99, $items['data'][0]->price);
        self::assertSame(self::FIXTURE_ITEM_2, $items['data'][1]->id);
        self::assertSame('SDK Smoke Item Two 090444b5', $items['data'][1]->title);
        // `include=variants` here: the included variants are spliced into each item's relationship.
        $variants = $items['data'][0]->getRelationship('variants');
        self::assertSame([
            '$custom:::$default:::sdk-smoke-090444b5-item1-v1',
            '$custom:::$default:::sdk-smoke-090444b5-item1-v2',
        ], $variants->ids());
        self::assertSame('SKU-090444B5-ITEM1-V1', $variants->data[0]->sku);
        self::assertSame('SKU-090444B5-ITEM2-V1', $items['data'][1]->getRelationship('variants')->data[0]->sku);

        $ids = $categories->itemIds(self::CATEGORY_ID, (new Query())->pageSize(10)->cursor('cur2'));
        self::assertSame('/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1/relationships/items', self::path($mock));
        self::assertSame(['page' => ['size' => '10', 'cursor' => 'cur2']], self::query($mock));
        self::assertCount(1, $ids['data']);
        self::assertInstanceOf(ResponseCatalogItem::class, $ids['data'][0]);
        self::assertSame(self::FIXTURE_ITEM_1, $ids['data'][0]->id);
        self::assertNull($ids['data'][0]->title);

    }

    public function testItemRelationshipMutations(): void
    {

        $mock = new MockHandler([new Response(204), new Response(204), new Response(204)]);
        $categories = self::client($mock)->catalogCategories;
        $relationshipPath = '/api/catalog-categories/$custom:::$default:::SAMPLE-DATA-CATEGORY-1/relationships/items';

        self::assertNull($categories->addItems(self::CATEGORY_ID, [new CatalogItem(self::ITEM_ID), new CatalogItem('$custom:::$default:::SAMPLE-DATA-ITEM-2')]));
        self::assertSame('POST', self::method($mock));
        self::assertSame($relationshipPath, self::path($mock));
        self::assertSame([
            'data' => [
                ['type' => 'catalog-item', 'id' => self::ITEM_ID],
                ['type' => 'catalog-item', 'id' => '$custom:::$default:::SAMPLE-DATA-ITEM-2'],
            ],
        ], self::body($mock));

        self::assertNull($categories->replaceItems(self::CATEGORY_ID, [new CatalogItem(self::ITEM_ID)]));
        self::assertSame('PATCH', self::method($mock));
        self::assertSame($relationshipPath, self::path($mock));
        self::assertSame(['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]], self::body($mock));

        self::assertNull($categories->removeItems(self::CATEGORY_ID, [new CatalogItem(self::ITEM_ID)]));
        self::assertSame('DELETE', self::method($mock));
        self::assertSame($relationshipPath, self::path($mock));
        self::assertSame(['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]], self::body($mock));

    }

    // endregion

    // region Bulk create jobs

    public function testBulkCreateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_create_catalog_categories.202'),
            Fixtures::response('get_bulk_create_categories_jobs.200'),
            Fixtures::response('get_bulk_create_categories_job.200'),
        ]);
        $categories = self::client($mock)->catalogCategories;

        $job = $categories->bulkCreate(new BulkCreateCatalogCategoriesJob([
            new CreateCatalogCategory('SUMMER', 'Summer'),
            new CreateCatalogCategory('WINTER', 'Winter', '$custom', '$default', [self::ITEM_ID]),
        ]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-category-bulk-create-job',
                'attributes' => [
                    'categories' => [
                        'data' => [
                            [
                                'type'       => 'catalog-category',
                                'attributes' => [
                                    'external_id'      => 'SUMMER',
                                    'name'             => 'Summer',
                                    'integration_type' => '$custom',
                                    'catalog_type'     => '$default',
                                ],
                            ],
                            [
                                'type'          => 'catalog-category',
                                'attributes'    => [
                                    'external_id'      => 'WINTER',
                                    'name'             => 'Winter',
                                    'integration_type' => '$custom',
                                    'catalog_type'     => '$default',
                                ],
                                'relationships' => [
                                    'items' => ['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategoryBulkCreateJob::class, $job);
        // The 202 already reports `processing` — Klaviyo never hands back a `queued` job here.
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);
        self::assertSame(0, $job->completed_count);
        self::assertSame([], $job->errors);

        $jobs = $categories->getBulkCreateJobs((new Query())->fields('catalog-category-bulk-create-job', 'status')->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'fields' => ['catalog-category-bulk-create-job' => 'status'],
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkCreateJob::class, $jobs['data'][0]);
        self::assertCount(3, $jobs['data']);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(2, $jobs['data'][0]->completed_count);
        self::assertSame([self::FIXTURE_BULK_CAT_1, self::FIXTURE_BULK_CAT_2], $jobs['data'][0]->getRelationship('categories')->ids());

        $fetched = $categories->getBulkCreateJob('bcj1', (new Query())->include('categories')->fields('catalog-category', 'name'));
        self::assertSame('/api/catalog-category-bulk-create-jobs/bcj1', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-category' => 'name'],
            'include' => 'categories',
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkCreateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(2, $fetched->total_count);
        self::assertSame(0, $fetched->completed_count);
        // `include=categories` on a still-processing job returns the relationship with empty `data`.
        self::assertSame([], $fetched->getRelationship('categories')->data);
        self::assertTrue($fetched->getRelationship('categories')->hasData);

    }

    // endregion

    // region Bulk update jobs

    public function testBulkUpdateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_update_catalog_categories.202'),
            Fixtures::response('get_bulk_update_categories_jobs.200'),
            Fixtures::response('get_bulk_update_categories_job.200'),
        ]);
        $categories = self::client($mock)->catalogCategories;

        $rename = new UpdateCatalogCategory(self::CATEGORY_ID);
        $rename->name = 'Renamed Category';
        $relink = new UpdateCatalogCategory('$custom:::$default:::SUMMER', [self::ITEM_ID]);

        $job = $categories->bulkUpdate(new BulkUpdateCatalogCategoriesJob([$rename, $relink]));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-update-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-category-bulk-update-job',
                'attributes' => [
                    'categories' => [
                        'data' => [
                            [
                                'type'       => 'catalog-category',
                                'attributes' => ['name' => 'Renamed Category'],
                                'id'         => self::CATEGORY_ID,
                            ],
                            [
                                'type'          => 'catalog-category',
                                'relationships' => [
                                    'items' => ['data' => [['type' => 'catalog-item', 'id' => self::ITEM_ID]]],
                                ],
                                'id'            => '$custom:::$default:::SUMMER',
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategoryBulkUpdateJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $categories->getBulkUpdateJobs((new Query())->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-update-jobs', self::path($mock));
        self::assertSame([
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkUpdateJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(2, $jobs['data'][0]->completed_count);
        self::assertSame([self::FIXTURE_BULK_CAT_1, self::FIXTURE_BULK_CAT_2], $jobs['data'][0]->getRelationship('categories')->ids());

        $fetched = $categories->getBulkUpdateJob('buj1', (new Query())->include('categories')->fields('catalog-category-bulk-update-job', 'status'));
        self::assertSame('/api/catalog-category-bulk-update-jobs/buj1', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-category-bulk-update-job' => 'status'],
            'include' => 'categories',
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkUpdateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(0, $fetched->failed_count);
        self::assertNull($fetched->completed_at);

    }

    // endregion

    // region Bulk delete jobs

    public function testBulkDeleteJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_delete_catalog_categories.202'),
            Fixtures::response('get_bulk_delete_categories_jobs.200'),
            Fixtures::response('get_bulk_delete_categories_job.200'),
        ]);
        $categories = self::client($mock)->catalogCategories;

        $job = $categories->bulkDelete(new BulkDeleteCatalogCategoriesJob([self::CATEGORY_ID, '$custom:::$default:::SUMMER']));
        self::assertSame('POST', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-delete-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-category-bulk-delete-job',
                'attributes' => [
                    'categories' => [
                        'data' => [
                            ['type' => 'catalog-category', 'id' => self::CATEGORY_ID],
                            ['type' => 'catalog-category', 'id' => '$custom:::$default:::SUMMER'],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogCategoryBulkDeleteJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $categories->getBulkDeleteJobs((new Query())->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('GET', self::method($mock));
        self::assertSame('/api/catalog-category-bulk-delete-jobs', self::path($mock));
        self::assertSame([
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkDeleteJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(2, $jobs['data'][0]->completed_count);
        self::assertSame([self::FIXTURE_BULK_CAT_1, self::FIXTURE_BULK_CAT_2], $jobs['data'][0]->getRelationship('categories')->ids());

        $fetched = $categories->getBulkDeleteJob('bdj1', (new Query())->fields('catalog-category-bulk-delete-job', 'status'));
        self::assertSame('/api/catalog-category-bulk-delete-jobs/bdj1', self::path($mock));
        self::assertSame(['fields' => ['catalog-category-bulk-delete-job' => 'status']], self::query($mock));
        self::assertInstanceOf(CatalogCategoryBulkDeleteJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame('2026-09-11T13:40:54.503797+00:00', $fetched->expires_at);
        self::assertSame(2, $fetched->completed_count);
        self::assertSame([self::FIXTURE_BULK_CAT_1, self::FIXTURE_BULK_CAT_2], $fetched->getRelationship('categories')->ids());

    }

    // endregion

}
