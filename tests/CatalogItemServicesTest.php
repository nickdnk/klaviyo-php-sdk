<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\CreateBackInStockSubscription;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogItem;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogVariant;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogItem;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogItem;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Shared\CatalogCategory as CategoryIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * Wire format for the catalog trio owned by `catalog-items`, `catalog-variants` and
 * `back-in-stock-subscriptions`: the synchronous CRUD pairs, the item ↔ category and
 * item ↔ variant relationships, all six bulk-job families, and the 202-empty back in stock
 * signup.
 *
 * Catalog ids are composite (`$custom:::$default:::SAMPLE-DATA-ITEM-1`) and both `$` and `:`
 * are legal path characters, so Guzzle emits them verbatim; the id assertions pin that so a
 * future encoding change cannot silently mangle a path.
 *
 * Responses come from the recorded fixtures in tests/fixtures/responses wherever one exists for
 * the operation, so the hydration assertions read back real Klaviyo payloads; the `FIXTURE_*`
 * ids below are the composite ids that corpus was recorded with.
 */
class CatalogItemServicesTest extends TestCase
{

    private const string ITEM_ID     = '$custom:::$default:::SAMPLE-DATA-ITEM-1';
    private const string VARIANT_ID  = '$custom:::$default:::SAMPLE-DATA-ITEM-1-VARIANT-1';
    private const string CATEGORY_ID = '$custom:::$default:::SAMPLE-DATA-CATEGORY-APPAREL';

    private const string FIXTURE_ITEM_1      = '$custom:::$default:::sdk-smoke-090444b5-item1';
    private const string FIXTURE_ITEM_2      = '$custom:::$default:::sdk-smoke-090444b5-item2';
    private const string FIXTURE_VARIANT_1   = '$custom:::$default:::sdk-smoke-090444b5-item1-v1';
    private const string FIXTURE_VARIANT_2   = '$custom:::$default:::sdk-smoke-090444b5-item1-v2';
    private const string FIXTURE_CATEGORY_1  = '$custom:::$default:::sdk-smoke-090444b5-cat1';
    private const string FIXTURE_BULK_ITEM_1 = '$custom:::$default:::sdk-smoke-090444b5-bitem1';
    private const string FIXTURE_BULK_VAR_1  = '$custom:::$default:::sdk-smoke-090444b5-bvar1';

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

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

    // region Catalog items

    public function testCatalogItemListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_catalog_items.200'),
            Fixtures::response('get_catalog_items.200'),
            Fixtures::response('get_catalog_item.200'),
            Fixtures::response('create_catalog_item.201'),
            Fixtures::response('update_catalog_item.200'),
            Fixtures::response('delete_catalog_item.204'),
        ]);
        $items = self::client($mock)->catalogItems;

        $list = $items->list(
            (new Query())->fields('catalog-item', 'title')->include('variants')->sort('created', true)->pageSize(50)->cursor('cur1')
        );
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-item' => 'title'],
            'include' => 'variants',
            'sort'    => '-created',
            'page'    => ['size' => '50', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(2, $list['data']);
        self::assertInstanceOf(CatalogItem::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_ITEM_1, $list['data'][0]->id);
        self::assertSame('SDK Smoke Item One 090444b5', $list['data'][0]->title);
        self::assertSame(19.99, $list['data'][0]->price);
        self::assertSame(['colour' => 'blue', 'smokeRun' => '090444b5', 'weightKg' => 1.25], $list['data'][0]->custom_metadata);
        // `include=variants` puts the variants in `included`; the SDK splices them into the relationship.
        $variantsOfFirst = $list['data'][0]->getRelationship('variants');
        self::assertSame([self::FIXTURE_VARIANT_1, self::FIXTURE_VARIANT_2], $variantsOfFirst->ids());
        self::assertInstanceOf(CatalogVariant::class, $variantsOfFirst->data[0]);
        self::assertSame('SKU-090444B5-ITEM1-V1', $variantsOfFirst->data[0]->sku);
        self::assertSame(24.99, $variantsOfFirst->data[1]->price);

        $page2 = $items->list(next: 'https://a.klaviyo.com/api/catalog-items?page%5Bcursor%5D=cur2');
        self::assertSame('/api/catalog-items', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'cur2']], self::query($mock));
        self::assertSame('SDK Smoke Item Two 090444b5', $page2['data'][1]->title);
        self::assertNull($page2['links']->next);

        $item = $items->get(self::ITEM_ID, (new Query())->fields('catalog-variant', 'sku')->include('variants'));
        self::assertSame('/api/catalog-items/' . self::ITEM_ID, self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-variant' => 'sku'],
            'include' => 'variants',
        ], self::query($mock));
        self::assertInstanceOf(CatalogItem::class, $item);
        self::assertSame(self::FIXTURE_ITEM_1, $item->id);
        self::assertTrue($item->published);
        self::assertSame('SDK Smoke Item One 090444b5', $item->title);
        $itemVariants = $item->getRelationship('variants');
        self::assertSame([self::FIXTURE_VARIANT_1, self::FIXTURE_VARIANT_2], $itemVariants->ids());
        self::assertSame('SKU-090444B5-ITEM1-V1', $itemVariants->data[0]->sku);
        self::assertSame(10, $itemVariants->data[0]->inventory_quantity);
        self::assertSame(0, $itemVariants->data[1]->inventory_quantity);

        $create = new CreateCatalogItem('SAMPLE-DATA-ITEM-1', 'Ceramic Mug', 'A mug', 'https://shop.test/mug', categoryIds: [self::CATEGORY_ID]);
        $create->price = 12.5;
        $create->image_full_url = 'https://shop.test/mug.png';
        $create->image_thumbnail_url = 'https://shop.test/mug-thumb.png';
        $create->images = ['https://shop.test/mug.png'];
        $create->custom_metadata = ['color' => 'blue'];
        $create->published = true;
        $created = $items->create($create, (new Query())->fields('catalog-item', 'title'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items', self::path($mock));
        self::assertSame(['fields' => ['catalog-item' => 'title']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'          => 'catalog-item',
                'attributes'    => [
                    'external_id'         => 'SAMPLE-DATA-ITEM-1',
                    'title'               => 'Ceramic Mug',
                    'description'         => 'A mug',
                    'url'                 => 'https://shop.test/mug',
                    'integration_type'    => '$custom',
                    'catalog_type'        => '$default',
                    'price'               => 12.5,
                    'image_full_url'      => 'https://shop.test/mug.png',
                    'image_thumbnail_url' => 'https://shop.test/mug-thumb.png',
                    'images'              => ['https://shop.test/mug.png'],
                    'custom_metadata'     => ['color' => 'blue'],
                    'published'           => true,
                ],
                'relationships' => [
                    'categories' => ['data' => [['type' => 'catalog-category', 'id' => self::CATEGORY_ID]]],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogItem::class, $created);
        self::assertSame(self::FIXTURE_ITEM_2, $created->id);
        self::assertSame('sdk-smoke-090444b5-item2', $created->external_id);
        self::assertSame(5.5, $created->price);
        self::assertSame(['smokeRun' => '090444b5'], $created->custom_metadata);
        // A create echoes the variants relationship as links only — no `data` member at all.
        self::assertFalse($created->getRelationship('variants')->hasData);

        $update = new UpdateCatalogItem(self::ITEM_ID, [self::CATEGORY_ID]);
        $update->title = 'Ceramic Mug XL';
        $update->price = 14.5;
        $updated = $items->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID, self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'catalog-item',
                'attributes'    => ['title' => 'Ceramic Mug XL', 'price' => 14.5],
                'relationships' => [
                    'categories' => ['data' => [['type' => 'catalog-category', 'id' => self::CATEGORY_ID]]],
                ],
                'id'            => self::ITEM_ID,
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogItem::class, $updated);
        self::assertSame(self::FIXTURE_ITEM_1, $updated->id);
        self::assertSame('SDK Smoke Item One RENAMED 090444b5', $updated->title);
        self::assertSame(29.5, $updated->price);
        self::assertSame(['https://example.com/img/sdk-smoke-090444b5-item1-3.jpg'], $updated->images);

        self::assertNull($items->delete(self::ITEM_ID));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID, self::path($mock));

    }

    public function testCompositeIdSurvivesTheRequestUriVerbatim(): void
    {

        $mock = new MockHandler([self::json(['type' => 'catalog-item', 'id' => self::ITEM_ID, 'attributes' => ['title' => 'Ceramic Mug']])]);

        self::client($mock)->catalogItems->get(self::ITEM_ID);

        $uri = $mock->getLastRequest()->getUri();
        self::assertSame('/api/catalog-items/$custom:::$default:::SAMPLE-DATA-ITEM-1', $uri->getPath());
        self::assertSame('https://a.klaviyo.com/api/catalog-items/$custom:::$default:::SAMPLE-DATA-ITEM-1', (string)$uri);
        self::assertStringNotContainsString('%24', (string)$uri);
        self::assertStringNotContainsString('%3A', (string)$uri);

    }

    public function testCatalogItemCategoryRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_categories_for_catalog_item.200'),
            Fixtures::response('get_category_ids_for_catalog_item.200'),
            new Response(204),
            // PATCH …/relationships/categories really answers 200 with an empty body, not 204.
            Fixtures::response('update_categories_for_catalog_item.200'),
            new Response(204),
        ]);
        $items = self::client($mock)->catalogItems;

        $categories = $items->categories(self::ITEM_ID, (new Query())->fields('catalog-category', 'name')->sort('created'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/categories', self::path($mock));
        self::assertSame([
            'fields' => ['catalog-category' => 'name'],
            'sort'   => 'created',
        ], self::query($mock));
        self::assertInstanceOf(CatalogCategory::class, $categories['data'][0]);
        self::assertSame(self::FIXTURE_CATEGORY_1, $categories['data'][0]->id);
        self::assertSame('SDK Smoke Cat One 090444b5', $categories['data'][0]->name);
        self::assertSame('sdk-smoke-090444b5-cat1', $categories['data'][0]->external_id);
        // The related-resource read carries the category's own `items` relationship as links only.
        self::assertFalse($categories['data'][0]->getRelationship('items')->hasData);

        $categoryIds = $items->categoryIds(self::ITEM_ID, (new Query())->pageSize(10));
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/relationships/categories', self::path($mock));
        self::assertSame(['page' => ['size' => '10']], self::query($mock));
        self::assertCount(1, $categoryIds['data']);
        self::assertInstanceOf(CatalogCategory::class, $categoryIds['data'][0]);
        self::assertSame(self::FIXTURE_CATEGORY_1, $categoryIds['data'][0]->id);
        self::assertNull($categoryIds['data'][0]->name);

        $linkage = [['type' => 'catalog-category', 'id' => self::CATEGORY_ID]];

        self::assertNull($items->addCategories(self::ITEM_ID, [new CategoryIdentifier(self::CATEGORY_ID)]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/relationships/categories', self::path($mock));
        self::assertSame(['data' => $linkage], self::body($mock));

        self::assertNull($items->replaceCategories(self::ITEM_ID, [new CategoryIdentifier(self::CATEGORY_ID)]));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/relationships/categories', self::path($mock));
        self::assertSame(['data' => $linkage], self::body($mock));

        self::assertNull($items->removeCategories(self::ITEM_ID, [new CategoryIdentifier(self::CATEGORY_ID)]));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/relationships/categories', self::path($mock));
        self::assertSame(['data' => $linkage], self::body($mock));

    }

    public function testCatalogItemVariantRelationships(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_variants_for_catalog_item.200'),
            Fixtures::response('get_variant_ids_for_catalog_item.200'),
        ]);
        $items = self::client($mock)->catalogItems;

        $variants = $items->variants(self::ITEM_ID, (new Query())->fields('catalog-variant', 'sku')->pageSize(25));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/variants', self::path($mock));
        self::assertSame([
            'fields' => ['catalog-variant' => 'sku'],
            'page'   => ['size' => '25'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogVariant::class, $variants['data'][0]);
        self::assertSame(self::FIXTURE_VARIANT_1, $variants['data'][0]->id);
        self::assertSame('SKU-090444B5-ITEM1-V1', $variants['data'][0]->sku);
        self::assertSame(10, $variants['data'][0]->inventory_quantity);
        self::assertSame(1, $variants['data'][0]->inventory_policy);
        self::assertSame(['size' => 'M', 'smokeRun' => '090444b5'], $variants['data'][0]->custom_metadata);

        $variantIds = $items->variantIds(self::ITEM_ID, (new Query())->filter(Filter::equals('published', true)));
        self::assertSame('/api/catalog-items/' . self::ITEM_ID . '/relationships/variants', self::path($mock));
        self::assertSame(['filter' => 'equals(published,true)'], self::query($mock));
        self::assertInstanceOf(CatalogVariant::class, $variantIds['data'][0]);
        // Recorded with sort=-created, so the newer variant comes first.
        self::assertSame([self::FIXTURE_VARIANT_2, self::FIXTURE_VARIANT_1], array_map(fn($v) => $v->id, $variantIds['data']));
        self::assertNull($variantIds['data'][0]->sku);

    }

    // endregion

    // region Catalog item bulk jobs

    public function testCatalogItemBulkCreateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_create_catalog_items.202'),
            Fixtures::response('get_bulk_create_catalog_items_jobs.200'),
            Fixtures::response('get_bulk_create_catalog_items_job.200'),
        ]);
        $items = self::client($mock)->catalogItems;

        $second = new CreateCatalogItem('SAMPLE-DATA-ITEM-2', 'Tote Bag', 'A bag', 'https://shop.test/bag');
        $second->published = false;
        $job = $items->bulkCreate(new BulkCreateCatalogItemsJob([
            new CreateCatalogItem('SAMPLE-DATA-ITEM-1', 'Ceramic Mug', 'A mug', 'https://shop.test/mug', categoryIds: [self::CATEGORY_ID]),
            $second,
        ]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-item-bulk-create-job',
                'attributes' => [
                    'items' => [
                        'data' => [
                            [
                                'type'          => 'catalog-item',
                                'attributes'    => [
                                    'external_id'      => 'SAMPLE-DATA-ITEM-1',
                                    'title'            => 'Ceramic Mug',
                                    'description'      => 'A mug',
                                    'url'              => 'https://shop.test/mug',
                                    'integration_type' => '$custom',
                                    'catalog_type'     => '$default',
                                ],
                                'relationships' => [
                                    'categories' => ['data' => [['type' => 'catalog-category', 'id' => self::CATEGORY_ID]]],
                                ],
                            ],
                            [
                                'type'       => 'catalog-item',
                                'attributes' => [
                                    'external_id'      => 'SAMPLE-DATA-ITEM-2',
                                    'title'            => 'Tote Bag',
                                    'description'      => 'A bag',
                                    'url'              => 'https://shop.test/bag',
                                    'integration_type' => '$custom',
                                    'catalog_type'     => '$default',
                                    'published'        => false,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogItemBulkCreateJob::class, $job);
        // The 202 already reports `processing` — Klaviyo never hands back a `queued` job here.
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);
        self::assertSame(0, $job->completed_count);
        self::assertNull($job->completed_at);
        self::assertSame([], $job->errors);

        $jobs = $items->getBulkCreateJobs((new Query())->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkCreateJob::class, $jobs['data'][0]);
        self::assertCount(3, $jobs['data']);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(2, $jobs['data'][0]->completed_count);
        self::assertSame(self::FIXTURE_BULK_ITEM_1, $jobs['data'][0]->getRelationship('items')->ids()[0]);

        $fetched = $items->getBulkCreateJob('ic1', (new Query())->include('items')->fields('catalog-item', 'title'));
        self::assertSame('/api/catalog-item-bulk-create-jobs/ic1', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-item' => 'title'],
            'include' => 'items',
        ], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkCreateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(2, $fetched->total_count);
        self::assertSame(0, $fetched->completed_count);
        // `include=items` on a still-processing job returns the relationship with an empty `data`.
        self::assertSame([], $fetched->getRelationship('items')->data);
        self::assertTrue($fetched->getRelationship('items')->hasData);

    }

    public function testCatalogItemBulkUpdateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_update_catalog_items.202'),
            Fixtures::response('get_bulk_update_catalog_items_jobs.200'),
            Fixtures::response('get_bulk_update_catalog_items_job.200'),
        ]);
        $items = self::client($mock)->catalogItems;

        $update = new UpdateCatalogItem(self::ITEM_ID);
        $update->title = 'Ceramic Mug XL';
        $update->custom_metadata = ['color' => 'blue'];
        $job = $items->bulkUpdate(new BulkUpdateCatalogItemsJob([$update]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-update-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-item-bulk-update-job',
                'attributes' => [
                    'items' => [
                        'data' => [
                            [
                                'type'       => 'catalog-item',
                                'attributes' => ['title' => 'Ceramic Mug XL', 'custom_metadata' => ['color' => 'blue']],
                                'id'         => self::ITEM_ID,
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogItemBulkUpdateJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $items->getBulkUpdateJobs((new Query())->filter(Filter::any('status', ['processing'])));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-update-jobs', self::path($mock));
        self::assertSame(['filter' => 'any(status,["processing"])'], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkUpdateJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame([self::FIXTURE_BULK_ITEM_1, '$custom:::$default:::sdk-smoke-090444b5-bitem2'], $jobs['data'][0]->getRelationship('items')->ids());
        // A job whose items no longer existed: `complete` with per-item errors and failed_count.
        $failed = $jobs['data'][2];
        self::assertSame('complete', $failed->status);
        self::assertSame(2, $failed->failed_count);
        self::assertSame(0, $failed->completed_count);
        self::assertSame('invalid', $failed->errors[0]['code']);
        self::assertSame('/data/attributes/items/data/id', $failed->errors[0]['source']['pointer']);

        $fetched = $items->getBulkUpdateJob('iu1', (new Query())->include('items'));
        self::assertSame('/api/catalog-item-bulk-update-jobs/iu1', self::path($mock));
        self::assertSame(['include' => 'items'], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkUpdateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(0, $fetched->failed_count);

    }

    public function testCatalogItemBulkDeleteJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_delete_catalog_items.202'),
            Fixtures::response('get_bulk_delete_catalog_items_jobs.200'),
            Fixtures::response('get_bulk_delete_catalog_items_job.200'),
        ]);
        $items = self::client($mock)->catalogItems;

        $job = $items->bulkDelete(new BulkDeleteCatalogItemsJob([self::ITEM_ID, '$custom:::$default:::SAMPLE-DATA-ITEM-2']));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-delete-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-item-bulk-delete-job',
                'attributes' => [
                    'items' => [
                        'data' => [
                            ['type' => 'catalog-item', 'id' => self::ITEM_ID],
                            ['type' => 'catalog-item', 'id' => '$custom:::$default:::SAMPLE-DATA-ITEM-2'],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogItemBulkDeleteJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $items->getBulkDeleteJobs((new Query())->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-item-bulk-delete-jobs', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'cur1']], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkDeleteJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame([self::FIXTURE_BULK_ITEM_1, '$custom:::$default:::sdk-smoke-090444b5-bitem2'], $jobs['data'][0]->getRelationship('items')->ids());
        self::assertSame(2, $jobs['data'][2]->failed_count);

        $fetched = $items->getBulkDeleteJob('id1', (new Query())->fields('catalog-item-bulk-delete-job', 'status'));
        self::assertSame('/api/catalog-item-bulk-delete-jobs/id1', self::path($mock));
        self::assertSame(['fields' => ['catalog-item-bulk-delete-job' => 'status']], self::query($mock));
        self::assertInstanceOf(CatalogItemBulkDeleteJob::class, $fetched);
        self::assertSame(2, $fetched->completed_count);
        self::assertNull($fetched->completed_at);

    }

    // endregion

    // region Catalog variants

    public function testCatalogVariantListGetCreateUpdateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_catalog_variants.200'),
            Fixtures::response('get_catalog_variant.200'),
            Fixtures::response('create_catalog_variant.201'),
            Fixtures::response('update_catalog_variant.200'),
            Fixtures::response('delete_catalog_variant.204'),
        ]);
        $variants = self::client($mock)->catalogVariants;

        $list = $variants->list((new Query())->fields('catalog-variant', 'sku', 'price')->sort('created')->pageSize(100));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variants', self::path($mock));
        self::assertSame([
            'fields' => ['catalog-variant' => 'sku,price'],
            'sort'   => 'created',
            'page'   => ['size' => '100'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogVariant::class, $list['data'][0]);
        self::assertSame(self::FIXTURE_VARIANT_1, $list['data'][0]->id);
        self::assertSame(19.99, $list['data'][0]->price);
        self::assertSame('SKU-090444B5-ITEM1-V1', $list['data'][0]->sku);
        self::assertTrue($list['data'][0]->published);
        self::assertFalse($list['data'][0]->getRelationship('item')->hasData);

        $variant = $variants->get(self::VARIANT_ID, (new Query())->fields('catalog-variant', 'sku'));
        self::assertSame('/api/catalog-variants/' . self::VARIANT_ID, self::path($mock));
        self::assertSame(['fields' => ['catalog-variant' => 'sku']], self::query($mock));
        self::assertInstanceOf(CatalogVariant::class, $variant);
        self::assertSame('$custom:::$default:::sdk-smoke-090444b5-bvar2', $variant->id);
        self::assertSame(8.88, $variant->price);
        self::assertSame(99, $variant->inventory_quantity);
        // A sparse fieldset really does leave the undelivered attributes unset, not zeroed.
        self::assertNull($variant->inventory_policy);

        $create = new CreateCatalogVariant(
            'SAMPLE-DATA-ITEM-1-VARIANT-1', 'Ceramic Mug / Large', 'The large mug', 'MUG-L',
            2, 42.0, 19.99, 'https://shop.test/mug-l', self::ITEM_ID
        );
        $create->images = ['https://shop.test/mug-l.png'];
        $created = $variants->create($create, (new Query())->fields('catalog-variant', 'external_id'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variants', self::path($mock));
        self::assertSame(['fields' => ['catalog-variant' => 'external_id']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'          => 'catalog-variant',
                'attributes'    => [
                    'external_id'        => 'SAMPLE-DATA-ITEM-1-VARIANT-1',
                    'title'              => 'Ceramic Mug / Large',
                    'description'        => 'The large mug',
                    'sku'                => 'MUG-L',
                    'inventory_policy'   => 2,
                    'inventory_quantity' => 42,
                    'price'              => 19.99,
                    'url'                => 'https://shop.test/mug-l',
                    'integration_type'   => '$custom',
                    'catalog_type'       => '$default',
                    'images'             => ['https://shop.test/mug-l.png'],
                ],
                'relationships' => [
                    'item' => ['data' => ['type' => 'catalog-item', 'id' => self::ITEM_ID]],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogVariant::class, $created);
        self::assertSame('$custom:::$default:::sdk-smoke-090444b5-item2-v1', $created->id);
        self::assertSame('sdk-smoke-090444b5-item2-v1', $created->external_id);
        self::assertSame(0, $created->inventory_policy);
        self::assertSame(3, $created->inventory_quantity);
        self::assertSame(5.5, $created->price);

        $update = new UpdateCatalogVariant(self::VARIANT_ID);
        $update->inventory_quantity = 7.5;
        $update->published = false;
        $updated = $variants->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variants/' . self::VARIANT_ID, self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-variant',
                'attributes' => ['inventory_quantity' => 7.5, 'published' => false],
                'id'         => self::VARIANT_ID,
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogVariant::class, $updated);
        self::assertSame(self::FIXTURE_VARIANT_1, $updated->id);
        self::assertSame('SKU-UPDATED-090444B5', $updated->sku);
        self::assertSame(42, $updated->inventory_quantity);
        self::assertSame(2, $updated->inventory_policy);
        self::assertTrue($updated->published);

        self::assertNull($variants->delete(self::VARIANT_ID));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variants/' . self::VARIANT_ID, self::path($mock));

    }

    // endregion

    // region Catalog variant bulk jobs

    public function testCatalogVariantBulkCreateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_create_catalog_variants.202'),
            Fixtures::response('get_bulk_create_variants_jobs.200'),
            Fixtures::response('get_bulk_create_variants_job.200'),
        ]);
        $variants = self::client($mock)->catalogVariants;

        $job = $variants->bulkCreate(new BulkCreateCatalogVariantsJob([
            new CreateCatalogVariant(
                'SAMPLE-DATA-ITEM-1-VARIANT-1', 'Ceramic Mug / Large', 'The large mug', 'MUG-L',
                1, 42.0, 19.99, 'https://shop.test/mug-l', self::ITEM_ID
            ),
        ]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-variant-bulk-create-job',
                'attributes' => [
                    'variants' => [
                        'data' => [
                            [
                                'type'          => 'catalog-variant',
                                'attributes'    => [
                                    'external_id'        => 'SAMPLE-DATA-ITEM-1-VARIANT-1',
                                    'title'              => 'Ceramic Mug / Large',
                                    'description'        => 'The large mug',
                                    'sku'                => 'MUG-L',
                                    'inventory_policy'   => 1,
                                    'inventory_quantity' => 42,
                                    'price'              => 19.99,
                                    'url'                => 'https://shop.test/mug-l',
                                    'integration_type'   => '$custom',
                                    'catalog_type'       => '$default',
                                ],
                                'relationships' => [
                                    'item' => ['data' => ['type' => 'catalog-item', 'id' => self::ITEM_ID]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogVariantBulkCreateJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $variants->getBulkCreateJobs((new Query())->filter(Filter::any('status', ['processing']))->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'filter' => 'any(status,["processing"])',
            'page'   => ['cursor' => 'cur1'],
        ], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkCreateJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame(2, $jobs['data'][0]->completed_count);
        self::assertSame(self::FIXTURE_BULK_VAR_1, $jobs['data'][0]->getRelationship('variants')->ids()[0]);

        $fetched = $variants->getBulkCreateJob('vc1', (new Query())->include('variants')->fields('catalog-variant', 'sku'));
        self::assertSame('/api/catalog-variant-bulk-create-jobs/vc1', self::path($mock));
        self::assertSame([
            'fields'  => ['catalog-variant' => 'sku'],
            'include' => 'variants',
        ], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkCreateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(0, $fetched->completed_count);
        self::assertSame([], $fetched->getRelationship('variants')->data);

    }

    public function testCatalogVariantBulkUpdateJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_update_catalog_variants.202'),
            Fixtures::response('get_bulk_update_variants_jobs.200'),
            Fixtures::response('get_bulk_update_variants_job.200'),
        ]);
        $variants = self::client($mock)->catalogVariants;

        $update = new UpdateCatalogVariant(self::VARIANT_ID);
        $update->inventory_quantity = 7.5;
        $update->inventory_policy = 0;
        $job = $variants->bulkUpdate(new BulkUpdateCatalogVariantsJob([$update]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-update-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-variant-bulk-update-job',
                'attributes' => [
                    'variants' => [
                        'data' => [
                            [
                                'type'       => 'catalog-variant',
                                'attributes' => ['inventory_quantity' => 7.5, 'inventory_policy' => 0],
                                'id'         => self::VARIANT_ID,
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogVariantBulkUpdateJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $variants->getBulkUpdateJobs((new Query())->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-update-jobs', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'cur1']], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkUpdateJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame([self::FIXTURE_BULK_VAR_1, '$custom:::$default:::sdk-smoke-090444b5-bvar2'], $jobs['data'][0]->getRelationship('variants')->ids());

        $fetched = $variants->getBulkUpdateJob('vu1', (new Query())->include('variants'));
        self::assertSame('/api/catalog-variant-bulk-update-jobs/vu1', self::path($mock));
        self::assertSame(['include' => 'variants'], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkUpdateJob::class, $fetched);
        self::assertSame('processing', $fetched->status);
        self::assertSame(0, $fetched->failed_count);

    }

    public function testCatalogVariantBulkDeleteJobFamily(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_delete_catalog_variants.202'),
            Fixtures::response('get_bulk_delete_variants_jobs.200'),
            Fixtures::response('get_bulk_delete_variants_job.200'),
        ]);
        $variants = self::client($mock)->catalogVariants;

        $job = $variants->bulkDelete(new BulkDeleteCatalogVariantsJob([self::VARIANT_ID]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-delete-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'catalog-variant-bulk-delete-job',
                'attributes' => [
                    'variants' => [
                        'data' => [['type' => 'catalog-variant', 'id' => self::VARIANT_ID]],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(CatalogVariantBulkDeleteJob::class, $job);
        self::assertSame('processing', $job->status);
        self::assertSame(2, $job->total_count);

        $jobs = $variants->getBulkDeleteJobs((new Query())->filter(Filter::any('status', ['processing'])));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/catalog-variant-bulk-delete-jobs', self::path($mock));
        self::assertSame(['filter' => 'any(status,["processing"])'], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkDeleteJob::class, $jobs['data'][0]);
        self::assertSame('complete', $jobs['data'][0]->status);
        self::assertSame([self::FIXTURE_BULK_VAR_1, '$custom:::$default:::sdk-smoke-090444b5-bvar2'], $jobs['data'][0]->getRelationship('variants')->ids());

        $fetched = $variants->getBulkDeleteJob('vd1', (new Query())->fields('catalog-variant-bulk-delete-job', 'status'));
        self::assertSame('/api/catalog-variant-bulk-delete-jobs/vd1', self::path($mock));
        self::assertSame(['fields' => ['catalog-variant-bulk-delete-job' => 'status']], self::query($mock));
        self::assertInstanceOf(CatalogVariantBulkDeleteJob::class, $fetched);
        self::assertSame('complete', $fetched->status);
        self::assertSame(2, $fetched->completed_count);

    }

    // endregion

    // region Back in stock subscriptions

    public function testBackInStockSubscriptionCreate(): void
    {

        $mock = new MockHandler([new Response(202)]);
        $subscriptions = self::client($mock)->backInStockSubscriptions;

        $profile = new ImportProfile();
        $profile->email = 'shopper@acme.test';
        $profile->phone_number = '+3212345678';
        $profile->external_id = 'acme-4711';

        self::assertNull($subscriptions->create(new CreateBackInStockSubscription(['EMAIL', 'SMS'], $profile, self::VARIANT_ID)));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/back-in-stock-subscriptions', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertSame([
            'data' => [
                'type'          => 'back-in-stock-subscription',
                'attributes'    => [
                    'channels' => ['EMAIL', 'SMS'],
                    'profile'  => [
                        'data' => [
                            'type'       => 'profile',
                            'attributes' => [
                                'email'        => 'shopper@acme.test',
                                'phone_number' => '+3212345678',
                                'external_id'  => 'acme-4711',
                            ],
                        ],
                    ],
                ],
                'relationships' => [
                    'variant' => ['data' => ['type' => 'catalog-variant', 'id' => self::VARIANT_ID]],
                ],
            ],
        ], self::body($mock));

    }

    public function testBackInStockSubscriptionKeepsAnExistingProfileId(): void
    {

        $mock = new MockHandler([new Response(202)]);

        self::client($mock)->backInStockSubscriptions->create(
            new CreateBackInStockSubscription(['PUSH'], new ImportProfile('01HQ3PROFILE'), self::VARIANT_ID)
        );
        self::assertSame('/api/back-in-stock-subscriptions', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'back-in-stock-subscription',
                'attributes'    => [
                    'channels' => ['PUSH'],
                    'profile'  => ['data' => ['type' => 'profile', 'id' => '01HQ3PROFILE']],
                ],
                'relationships' => [
                    'variant' => ['data' => ['type' => 'catalog-variant', 'id' => self::VARIANT_ID]],
                ],
            ],
        ], self::body($mock));

    }

    // endregion

}
