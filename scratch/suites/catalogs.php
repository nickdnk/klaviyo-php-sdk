<?php
/**
 * CatalogItemService + CatalogCategoryService + CatalogVariantService +
 * BackInStockSubscriptionService, end to end.
 *
 * The catalog on this account is empty, so every resource the suite reads is one it created.
 * Catalog ids are composite: `{integration}:::{catalog}:::{external_id}`, i.e.
 * `$custom:::$default:::sdk-smoke-<run>-item1`, which is what the Create* request classes
 * build server-side from external_id + integration_type + catalog_type.
 *
 * Spec notes that shape the query knobs used below (scratch/openapi/stable.json):
 *  - sort on every catalog collection is `created` / `-created` only (no `-title`).
 *  - catalog-items filter: any(ids), equals(category.id), contains(title), equals(published).
 *  - catalog-variants filter: any(ids), equals(item.id), equals(sku), contains(title), equals(published).
 *  - catalog-categories filter: any(ids), equals(item.id), contains(name)  — `name`, not `title`.
 *  - `include` exists only on catalog-items (variants), catalog-categories/{id}/items (variants)
 *    and the bulk create/update job GETs. catalog-variants and catalog-categories GETs take
 *    fields only, and the bulk *delete* job GET takes fields only.
 *  - bulk job list endpoints take fields + filter + page[cursor] — no page[size], no sort.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\CreateBackInStockSubscription;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogCategory;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogItem;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogVariant;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogCategory;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogItem;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogItem;
use nickdnk\Klaviyo\Resources\Response\CatalogVariant;
use Smoke\Harness;

return function (Harness $h): void {

    /** composite id Klaviyo builds from an external id */
    $cid = static fn(string $externalId): string => '$custom:::$default:::' . $externalId;

    $ITEM_FIELDS = ['created', 'custom_metadata', 'description', 'external_id', 'image_full_url', 'image_thumbnail_url', 'images', 'price', 'published', 'title', 'updated', 'url'];
    $VARIANT_FIELDS = ['created', 'custom_metadata', 'description', 'external_id', 'image_full_url', 'image_thumbnail_url', 'images', 'inventory_policy', 'inventory_quantity', 'price', 'published', 'sku', 'title', 'updated', 'url'];
    $CAT_FIELDS = ['external_id', 'name', 'updated'];
    $JOB_FIELDS = ['completed_at', 'completed_count', 'created_at', 'errors', 'expires_at', 'failed_count', 'status', 'total_count'];

    // ───────────────────────── Categories: create ─────────────────────────

    $cat1Ext = $h->name('cat1');
    $cat2Ext = $h->name('cat2');

    /** @var CatalogCategory|null $cat1 */
    $cat1 = $h->step('catalogCategories', 'create', 'create category (external_id + name), fields[catalog-category]',
        fn(APIClient $c) => $c->catalogCategories->create(
            new CreateCatalogCategory($cat1Ext, 'SDK Smoke Cat One ' . $h->runId),
            (new Query())->fields('catalog-category', ...$CAT_FIELDS)
        ),
        fn($r) => $h->assert(
            $r instanceof CatalogCategory && $r->id === $cid($cat1Ext) && $r->external_id === $cat1Ext && $r->name === 'SDK Smoke Cat One ' . $h->runId,
            'composite id `$custom:::$default:::' . $cat1Ext . '` + attributes echoed'
        ));
    if (!$cat1) {
        $h->note('Cannot continue without a catalog category.');
        return;
    }
    $h->cleanup('catalogCategories', 'delete', 'cat1', fn(APIClient $c) => $c->catalogCategories->delete($cat1->id));

    /** @var CatalogCategory|null $cat2 */
    $cat2 = $h->step('catalogCategories', 'create', 'create second category (explicit integration/catalog type)',
        fn(APIClient $c) => $c->catalogCategories->create(new CreateCatalogCategory($cat2Ext, 'SDK Smoke Cat Two ' . $h->runId, '$custom', '$default')),
        fn($r) => $h->assert($r instanceof CatalogCategory && $r->id === $cid($cat2Ext), 'second category'));
    if ($cat2) {
        $h->cleanup('catalogCategories', 'delete', 'cat2', fn(APIClient $c) => $c->catalogCategories->delete($cat2->id));
    }

    // ───────────────────────── Items: create ─────────────────────────

    $item1Ext = $h->name('item1');
    $item2Ext = $h->name('item2');

    /** @var CatalogItem|null $item1 */
    $item1 = $h->step('catalogItems', 'create', 'create item (price, images, custom_metadata, published, categories rel) + fields[catalog-item]',
        function (APIClient $c) use ($h, $cat1, $item1Ext, $ITEM_FIELDS) {
            $i = new CreateCatalogItem($item1Ext, 'SDK Smoke Item One ' . $h->runId, 'First smoke item.', 'https://example.com/products/' . $item1Ext, '$custom', '$default', [$cat1->id]);
            $i->price = 19.99;
            $i->image_full_url = 'https://example.com/img/' . $item1Ext . '-full.jpg';
            $i->image_thumbnail_url = 'https://example.com/img/' . $item1Ext . '-thumb.jpg';
            $i->images = ['https://example.com/img/' . $item1Ext . '-1.jpg', 'https://example.com/img/' . $item1Ext . '-2.jpg'];
            $i->custom_metadata = ['smokeRun' => $h->runId, 'colour' => 'blue', 'weightKg' => 1.25];
            $i->published = true;

            return $c->catalogItems->create($i, (new Query())->fields('catalog-item', ...$ITEM_FIELDS));
        },
        fn($r) => $h->assert(
            $r instanceof CatalogItem && $r->id === $cid($item1Ext) && $r->price === 19.99 && $r->published === true
            && is_array($r->images) && count($r->images) === 2 && ($r->custom_metadata['colour'] ?? null) === 'blue',
            'item hydrated with price/images/custom_metadata/published'
        ));
    if (!$item1) {
        $h->note('Cannot continue without a catalog item.');
        return;
    }
    $h->cleanup('catalogItems', 'delete', 'item1', fn(APIClient $c) => $c->catalogItems->delete($item1->id));

    /** @var CatalogItem|null $item2 */
    $item2 = $h->step('catalogItems', 'create', 'create second item in both categories',
        function (APIClient $c) use ($h, $cat1, $cat2, $item2Ext) {
            $i = new CreateCatalogItem($item2Ext, 'SDK Smoke Item Two ' . $h->runId, 'Second smoke item.', 'https://example.com/products/' . $item2Ext);
            $i->setCategories(array_filter([$cat1->id, $cat2?->id]));
            $i->price = 5.5;
            $i->published = true;
            $i->custom_metadata = ['smokeRun' => $h->runId];

            return $c->catalogItems->create($i);
        },
        fn($r) => $h->assert($r instanceof CatalogItem && $r->id === $cid($item2Ext), 'second item'));
    if ($item2) {
        $h->cleanup('catalogItems', 'delete', 'item2', fn(APIClient $c) => $c->catalogItems->delete($item2->id));
    }

    // ───────────────────────── Variants: create ─────────────────────────

    /** @var array<string, CatalogVariant> $variants  external id => variant */
    $variants = [];
    $variantPlan = [
        ['ext' => $h->name('item1-v1'), 'item' => $item1->id, 'policy' => 1, 'qty' => 10.0, 'price' => 19.99],
        ['ext' => $h->name('item1-v2'), 'item' => $item1->id, 'policy' => 2, 'qty' => 0.0, 'price' => 24.99],
    ];
    if ($item2) {
        $variantPlan[] = ['ext' => $h->name('item2-v1'), 'item' => $item2->id, 'policy' => 0, 'qty' => 3.0, 'price' => 5.5];
        $variantPlan[] = ['ext' => $h->name('item2-v2'), 'item' => $item2->id, 'policy' => 1, 'qty' => 7.0, 'price' => 6.5];
    }

    foreach ($variantPlan as $n => $plan) {
        $label = 'create variant ' . ($n + 1) . ' (sku, inventory_policy=' . $plan['policy'] . ', inventory_quantity, price, published)';
        $v = $h->step('catalogVariants', 'create', $label,
            function (APIClient $c) use ($h, $plan, $VARIANT_FIELDS) {
                $v = new CreateCatalogVariant(
                    $plan['ext'],
                    'SDK Smoke Variant ' . $plan['ext'],
                    'Variant of a smoke item.',
                    strtoupper(str_replace('sdk-smoke-', 'SKU-', $plan['ext'])),
                    $plan['policy'],
                    $plan['qty'],
                    $plan['price'],
                    'https://example.com/products/' . $plan['ext'],
                    $plan['item'],
                );
                $v->published = true;
                $v->images = ['https://example.com/img/' . $plan['ext'] . '.jpg'];
                $v->custom_metadata = ['smokeRun' => $h->runId, 'size' => 'M'];

                return $c->catalogVariants->create($v, (new Query())->fields('catalog-variant', ...$VARIANT_FIELDS));
            },
            fn($r) => $h->assert(
                $r instanceof CatalogVariant && $r->id === $cid($plan['ext']) && (float)$r->price === $plan['price']
                && (float)$r->inventory_quantity === $plan['qty'] && (int)$r->inventory_policy === $plan['policy'],
                'variant hydrated (price/inventory)'
            ));
        if ($v) {
            $variants[$plan['ext']] = $v;
            $h->cleanup('catalogVariants', 'delete', 'variant ' . $plan['ext'], fn(APIClient $c) => $c->catalogVariants->delete($v->id));
        }
    }
    $var1 = $variants[$h->name('item1-v1')] ?? null;
    $var2 = $variants[$h->name('item1-v2')] ?? null;

    // ───────────────────────── Items: read ─────────────────────────

    $h->step('catalogItems', 'get', 'get item w/ fields[catalog-item] + fields[catalog-variant] + include(variants)',
        fn(APIClient $c) => $c->catalogItems->get($item1->id, (new Query())
            ->fields('catalog-item', 'title', 'price', 'published', 'external_id')
            ->fields('catalog-variant', 'title', 'sku', 'inventory_quantity')
            ->include('variants')),
        function ($r) use ($h, $item1, $variants) {
            $h->assert($r instanceof CatalogItem && $r->id === $item1->id && $r->title !== null, 'item returned');
            $rel = $r->getRelationship('variants');
            $h->assert($rel !== null && is_array($rel->data) && count($rel->data) === 2, 'variants relationship hydrated with 2 identifiers');
            $h->assert($rel->data[0] instanceof CatalogVariant && $rel->data[0]->id !== null, 'relationship entries typed');
        });

    $h->step('catalogItems', 'get', 'get unknown item id → null',
        fn(APIClient $c) => $c->catalogItems->get($cid('sdk-smoke-nope-' . $h->runId)),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('catalogItems', 'list', 'list w/ filter any(ids,[..]) + fields + include(variants) + sort=created + page[size]=100',
        fn(APIClient $c) => $c->catalogItems->list((new Query())
            ->filter(Filter::any('ids', array_filter([$item1->id, $item2?->id])))
            ->fields('catalog-item', ...$ITEM_FIELDS)
            ->fields('catalog-variant', 'sku', 'price')
            ->include('variants')
            ->sort('created')
            ->pageSize(100)),
        function ($r) use ($h, $item2) {
            $h->assert(is_array($r['data']) && count($r['data']) === ($item2 ? 2 : 1), 'any(ids) returns exactly ours');
            $h->assert($r['data'][0] instanceof CatalogItem, 'typed');
            $h->assert(array_key_exists('links', $r), 'links key always present');
            $h->assert(is_array($r['included'] ?? null) && $r['included'] !== [], 'JSON:API `included` present in the raw result array');
        });

    if ($cat2 && $item2) {
        $h->step('catalogItems', 'list', 'list w/ filter equals(category.id,cat2)',
            fn(APIClient $c) => $c->catalogItems->list((new Query())
                ->filter(Filter::equals('category.id', $cat2->id))
                ->fields('catalog-item', 'title', 'external_id')),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $item2->id, 'category.id filter returns only cat2 members (item2)'));
    }

    $h->step('catalogItems', 'list', 'list w/ filter all(contains(title,run), equals(published,true)) + sort=-created',
        fn(APIClient $c) => $c->catalogItems->list((new Query())
            ->filter(Filter::all(Filter::contains('title', $h->runId), Filter::equals('published', true)))
            ->sort('created', descending: true)
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === ($item2 ? 2 : 1), 'contains(title)+equals(published) combined filter'));

    $h->step('catalogItems', 'list', 'paginate items via page[size]=1 + links.next + Query::cursor()',
        function (APIClient $c) use ($h) {
            $q = (new Query())->filter(Filter::contains('title', $h->runId))->pageSize(1)->sort('created');
            $p1 = $c->catalogItems->list($q);
            $h->assert(count($p1['data']) === 1, 'first page has 1');
            $h->assert($p1['links']?->next !== null, 'links.next present');
            $p2 = $c->catalogItems->list(next: $p1['links']->next);
            $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'second page differs');
            $viaCursor = $c->catalogItems->list((new Query())->filter(Filter::contains('title', $h->runId))->pageSize(1)->sort('created')->cursor($p1['links']->next));
            $h->assert($viaCursor['data'][0]->id === $p2['data'][0]->id, 'Query::cursor(links.next url) == next');

            return $p2;
        });

    $h->step('catalogItems', 'categories', 'categories for item1 w/ fields + sort=-created + page[size]=100',
        fn(APIClient $c) => $c->catalogItems->categories($item1->id, (new Query())
            ->fields('catalog-category', ...$CAT_FIELDS)
            ->sort('created', descending: true)
            ->pageSize(100)),
        function ($r) use ($h, $cat1) {
            $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $cat1->id, 'item1 is in cat1 only');
            $h->assert($r['data'][0] instanceof CatalogCategory && $r['data'][0]->name !== null, 'attributes hydrated');
        });

    $h->step('catalogItems', 'categories', 'categories for item1 w/ filter contains(name,run)',
        fn(APIClient $c) => $c->catalogItems->categories($item1->id, (new Query())->filter(Filter::contains('name', $h->runId))),
        fn($r) => $h->assert(count($r['data']) === 1, 'contains(name) filter on the relation'));

    $h->step('catalogItems', 'categoryIds', 'category ids for item1 (identifiers only) + page[size]',
        fn(APIClient $c) => $c->catalogItems->categoryIds($item1->id, (new Query())->pageSize(100)),
        function ($r) use ($h, $cat1) {
            $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $cat1->id, 'one id');
            $h->assert($r['data'][0] instanceof CatalogCategory && !isset($r['data'][0]->name), 'identifier only, no attributes');
        });

    $h->step('catalogItems', 'variants', 'variants for item1 w/ fields + sort=created + page[size]=100',
        fn(APIClient $c) => $c->catalogItems->variants($item1->id, (new Query())
            ->fields('catalog-variant', ...$VARIANT_FIELDS)
            ->sort('created')
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === 2 && $r['data'][0] instanceof CatalogVariant && $r['data'][0]->sku !== null, '2 variants w/ sku'));

    if ($var1) {
        $h->step('catalogItems', 'variants', 'variants for item1 w/ filter all(equals(sku,..), equals(published,true))',
            fn(APIClient $c) => $c->catalogItems->variants($item1->id, (new Query())
                ->filter(Filter::all(Filter::equals('sku', $var1->sku), Filter::equals('published', true)))),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $var1->id, 'sku filter narrows to one'));
    }

    $h->step('catalogItems', 'variantIds', 'variant ids for item1 + filter contains(title,run) + sort=-created',
        fn(APIClient $c) => $c->catalogItems->variantIds($item1->id, (new Query())
            ->filter(Filter::contains('title', $h->runId))
            ->sort('created', descending: true)
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === 2 && $r['data'][0] instanceof CatalogVariant && !isset($r['data'][0]->sku), '2 identifiers, no attributes'));

    $h->step('catalogItems', 'variants', 'paginate item variants via links.next (page[size]=1)',
        function (APIClient $c) use ($h, $item1) {
            $p1 = $c->catalogItems->variants($item1->id, (new Query())->pageSize(1)->sort('created'));
            $h->assert(count($p1['data']) === 1 && $p1['links']?->next !== null, '1 + next');
            $p2 = $c->catalogItems->variants($item1->id, next: $p1['links']->next);
            $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');

            return $p2;
        });

    // ───────────────────────── Categories: read ─────────────────────────

    $h->step('catalogCategories', 'get', 'get category w/ fields[catalog-category]',
        fn(APIClient $c) => $c->catalogCategories->get($cat1->id, (new Query())->fields('catalog-category', ...$CAT_FIELDS)),
        fn($r) => $h->assert($r instanceof CatalogCategory && $r->id === $cat1->id && $r->external_id === $cat1->external_id && $r->updated !== null, 'category hydrated'));

    $h->step('catalogCategories', 'get', 'get unknown category id → null',
        fn(APIClient $c) => $c->catalogCategories->get($cid('sdk-smoke-nope-' . $h->runId)),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('catalogCategories', 'list', 'list w/ filter any(ids,[..]) + fields + sort=created + page[size]=100',
        fn(APIClient $c) => $c->catalogCategories->list((new Query())
            ->filter(Filter::any('ids', array_filter([$cat1->id, $cat2?->id])))
            ->fields('catalog-category', ...$CAT_FIELDS)
            ->sort('created')
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === ($cat2 ? 2 : 1), 'any(ids)'));

    $h->step('catalogCategories', 'list', 'list w/ filter equals(item.id,item1)',
        fn(APIClient $c) => $c->catalogCategories->list((new Query())->filter(Filter::equals('item.id', $item1->id))),
        fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $cat1->id, 'item.id filter → cat1'));

    $h->step('catalogCategories', 'list', 'list w/ filter contains(name,run) + sort=-created',
        fn(APIClient $c) => $c->catalogCategories->list((new Query())->filter(Filter::contains('name', $h->runId))->sort('created', descending: true)->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === ($cat2 ? 2 : 1), 'contains(name)'));

    if ($cat2) {
        $h->step('catalogCategories', 'list', 'paginate categories via links.next (page[size]=1)',
            function (APIClient $c) use ($h) {
                $p1 = $c->catalogCategories->list((new Query())->filter(Filter::contains('name', $h->runId))->pageSize(1)->sort('created'));
                $h->assert(count($p1['data']) === 1 && $p1['links']?->next !== null, '1 + next');
                $p2 = $c->catalogCategories->list(next: $p1['links']->next);
                $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');

                return $p2;
            });
    }

    $h->step('catalogCategories', 'items', 'items for cat1 w/ fields[catalog-item] + fields[catalog-variant] + include(variants) + sort + page[size]',
        fn(APIClient $c) => $c->catalogCategories->items($cat1->id, (new Query())
            ->fields('catalog-item', 'title', 'price', 'published')
            ->fields('catalog-variant', 'sku')
            ->include('variants')
            ->sort('created')
            ->pageSize(100)),
        function ($r) use ($h, $item2) {
            $h->assert(count($r['data']) === ($item2 ? 2 : 1), 'both items are in cat1');
            $h->assert($r['data'][0] instanceof CatalogItem, 'typed');
            $h->assert(is_array($r['included'] ?? null) && $r['included'] !== [], '`included` variants present in raw result');
        });

    $h->step('catalogCategories', 'items', 'items for cat1 w/ filter all(contains(title,run), equals(published,true))',
        fn(APIClient $c) => $c->catalogCategories->items($cat1->id, (new Query())
            ->filter(Filter::all(Filter::contains('title', $h->runId), Filter::equals('published', true)))),
        fn($r) => $h->assert(count($r['data']) === ($item2 ? 2 : 1), 'combined filter on the relation'));

    $h->step('catalogCategories', 'itemIds', 'item ids for cat1 (identifiers only) + sort=-created + page[size]=100',
        fn(APIClient $c) => $c->catalogCategories->itemIds($cat1->id, (new Query())->sort('created', descending: true)->pageSize(100)),
        function ($r) use ($h, $item2) {
            $h->assert(count($r['data']) === ($item2 ? 2 : 1), 'ids for both items');
            $h->assert($r['data'][0] instanceof CatalogItem && !isset($r['data'][0]->title), 'identifier only');
        });

    if ($item2) {
        $h->step('catalogCategories', 'items', 'paginate category items via links.next (page[size]=1)',
            function (APIClient $c) use ($h, $cat1) {
                $p1 = $c->catalogCategories->items($cat1->id, (new Query())->pageSize(1)->sort('created'));
                $h->assert(count($p1['data']) === 1 && $p1['links']?->next !== null, '1 + next');
                $p2 = $c->catalogCategories->items($cat1->id, next: $p1['links']->next);
                $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');

                return $p2;
            });
    }

    // ───────────────────────── Variants: read ─────────────────────────

    if ($var1) {
        $h->step('catalogVariants', 'get', 'get variant w/ fields[catalog-variant]',
            fn(APIClient $c) => $c->catalogVariants->get($var1->id, (new Query())->fields('catalog-variant', ...$VARIANT_FIELDS)),
            fn($r) => $h->assert($r instanceof CatalogVariant && $r->id === $var1->id && $r->sku !== null && $r->created !== null, 'variant hydrated'));

        $h->skip('catalogVariants', 'get', 'get variant w/ include(item)',
            'GET /catalog-variants/{id} declares fields[catalog-variant] only; probed once and Klaviyo answers 400 invalid: "\'item\' include is not currently supported for the requested operation on this resource."');
    }

    $h->step('catalogVariants', 'get', 'get unknown variant id → null',
        fn(APIClient $c) => $c->catalogVariants->get($cid('sdk-smoke-nope-' . $h->runId)),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('catalogVariants', 'list', 'list w/ filter any(ids,[..]) + fields + sort=created + page[size]=100',
        fn(APIClient $c) => $c->catalogVariants->list((new Query())
            ->filter(Filter::any('ids', array_map(fn(CatalogVariant $v) => $v->id, array_values($variants))))
            ->fields('catalog-variant', ...$VARIANT_FIELDS)
            ->sort('created')
            ->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === count($variants), 'any(ids) returns all our variants'));

    $h->step('catalogVariants', 'list', 'list w/ filter equals(item.id,item1)',
        fn(APIClient $c) => $c->catalogVariants->list((new Query())->filter(Filter::equals('item.id', $item1->id))->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === 2, 'item.id filter → item1 variants'));

    if ($var1) {
        $h->step('catalogVariants', 'list', 'list w/ filter all(equals(sku,..), equals(published,true))',
            fn(APIClient $c) => $c->catalogVariants->list((new Query())->filter(Filter::all(Filter::equals('sku', $var1->sku), Filter::equals('published', true)))),
            fn($r) => $h->assert(count($r['data']) === 1 && $r['data'][0]->id === $var1->id, 'sku filter'));
    }

    $h->step('catalogVariants', 'list', 'list w/ filter contains(title,run) + sort=-created',
        fn(APIClient $c) => $c->catalogVariants->list((new Query())->filter(Filter::contains('title', $h->runId))->sort('created', descending: true)->pageSize(100)),
        fn($r) => $h->assert(count($r['data']) === count($variants), 'contains(title)'));

    $h->step('catalogVariants', 'list', 'paginate variants via links.next (page[size]=1)',
        function (APIClient $c) use ($h) {
            $p1 = $c->catalogVariants->list((new Query())->filter(Filter::contains('title', $h->runId))->pageSize(1)->sort('created'));
            $h->assert(count($p1['data']) === 1 && $p1['links']?->next !== null, '1 + next');
            $p2 = $c->catalogVariants->list(next: $p1['links']->next);
            $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'page 2 differs');

            return $p2;
        });

    // ───────────────────────── Updates ─────────────────────────

    $h->step('catalogItems', 'update', 'update item title/price/published/images/custom_metadata + fields[catalog-item]',
        function (APIClient $c) use ($h, $item1, $item1Ext, $ITEM_FIELDS) {
            $u = new UpdateCatalogItem($item1->id);
            $u->title = 'SDK Smoke Item One RENAMED ' . $h->runId;
            $u->description = 'Updated description.';
            $u->url = 'https://example.com/products/' . $item1Ext . '?v=2';
            $u->price = 29.5;
            $u->published = false;
            $u->images = ['https://example.com/img/' . $item1Ext . '-3.jpg'];
            $u->custom_metadata = ['smokeRun' => $h->runId, 'colour' => 'red'];

            return $c->catalogItems->update($u, (new Query())->fields('catalog-item', ...$ITEM_FIELDS));
        },
        fn($r) => $h->assert(
            $r instanceof CatalogItem && $r->title === 'SDK Smoke Item One RENAMED ' . $h->runId && $r->price === 29.5
            && $r->published === false && ($r->custom_metadata['colour'] ?? null) === 'red',
            'attributes changed'
        ));

    $h->step('catalogItems', 'update', 'republish item (published=true) so later filters still match',
        function (APIClient $c) use ($item1) {
            $u = new UpdateCatalogItem($item1->id);
            $u->published = true;

            return $c->catalogItems->update($u);
        },
        fn($r) => $h->assert($r instanceof CatalogItem && $r->published === true, 'republished'));

    $h->step('catalogCategories', 'update', 'update category name + fields[catalog-category]',
        function (APIClient $c) use ($h, $cat1, $CAT_FIELDS) {
            $u = new UpdateCatalogCategory($cat1->id);
            $u->name = 'SDK Smoke Cat One RENAMED ' . $h->runId;

            return $c->catalogCategories->update($u, (new Query())->fields('catalog-category', ...$CAT_FIELDS));
        },
        fn($r) => $h->assert($r instanceof CatalogCategory && $r->name === 'SDK Smoke Cat One RENAMED ' . $h->runId, 'renamed'));

    if ($var1) {
        $h->step('catalogVariants', 'update', 'update variant price/sku/inventory_policy/inventory_quantity/published + fields',
            function (APIClient $c) use ($h, $var1, $VARIANT_FIELDS) {
                $u = new UpdateCatalogVariant($var1->id);
                $u->title = 'SDK Smoke Variant RENAMED ' . $h->runId;
                $u->description = 'Updated variant description.';
                $u->sku = 'SKU-UPDATED-' . strtoupper($h->runId);
                $u->inventory_policy = 2;
                $u->inventory_quantity = 42.0;
                $u->price = 21.5;
                $u->published = true;
                $u->images = ['https://example.com/img/updated.jpg'];
                $u->custom_metadata = ['smokeRun' => $h->runId, 'size' => 'L'];

                return $c->catalogVariants->update($u, (new Query())->fields('catalog-variant', ...$VARIANT_FIELDS));
            },
            fn($r) => $h->assert(
                $r instanceof CatalogVariant && (float)$r->price === 21.5 && (float)$r->inventory_quantity === 42.0
                && (int)$r->inventory_policy === 2 && $r->sku === 'SKU-UPDATED-' . strtoupper($h->runId),
                'variant attributes changed'
            ));
    }

    // ───────────── Relationships: item → categories, category → items ─────────────

    if ($item2 && $cat2) {
        // item2 was created in [cat1, cat2]; Klaviyo answers 409 on POST relationships for a
        // link that already exists (it is not idempotent), so remove before adding.
        $h->step('catalogItems', 'removeCategories', 'remove cat2 from item2, verify via categoryIds',
            function (APIClient $c) use ($h, $item2, $cat1, $cat2) {
                $r = $c->catalogItems->removeCategories($item2->id, [new \nickdnk\Klaviyo\Resources\Shared\CatalogCategory($cat2->id)]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogItems->categoryIds($item2->id);
                $h->assert(count($ids['data']) === 1 && $ids['data'][0]->id === $cat1->id, 'only cat1 left');

                return $r;
            });

        $h->step('catalogItems', 'addCategories', 'add cat2 back to item2, verify via categoryIds',
            function (APIClient $c) use ($h, $item2, $cat2) {
                $r = $c->catalogItems->addCategories($item2->id, [new \nickdnk\Klaviyo\Resources\Shared\CatalogCategory($cat2->id)]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogItems->categoryIds($item2->id);
                $h->assert(count($ids['data']) === 2, 'item2 in 2 categories');

                return $r;
            });

        $h->step('catalogItems', 'replaceCategories', 'replace item2 categories with [cat2] only, verify',
            function (APIClient $c) use ($h, $item2, $cat2) {
                $r = $c->catalogItems->replaceCategories($item2->id, [new \nickdnk\Klaviyo\Resources\Shared\CatalogCategory($cat2->id)]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogItems->categoryIds($item2->id);
                $h->assert(count($ids['data']) === 1 && $ids['data'][0]->id === $cat2->id, 'set replaced by cat2');

                return $r;
            });

        $h->step('catalogCategories', 'addItems', 'add item2 back into cat1 from the category side, verify via itemIds',
            function (APIClient $c) use ($h, $item2, $cat1) {
                $r = $c->catalogCategories->addItems($cat1->id, [new \nickdnk\Klaviyo\Resources\Shared\CatalogItem($item2->id)]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogCategories->itemIds($cat1->id);
                $h->assert(count($ids['data']) === 2, 'cat1 holds both items');

                return $r;
            });

        $h->step('catalogCategories', 'removeItems', 'remove item2 from cat1, verify',
            function (APIClient $c) use ($h, $item1, $item2, $cat1) {
                $r = $c->catalogCategories->removeItems($cat1->id, [new \nickdnk\Klaviyo\Resources\Shared\CatalogItem($item2->id)]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogCategories->itemIds($cat1->id);
                $h->assert(count($ids['data']) === 1 && $ids['data'][0]->id === $item1->id, 'only item1 left in cat1');

                return $r;
            });

        $h->step('catalogCategories', 'replaceItems', 'replace cat1 item set with [item1,item2], verify',
            function (APIClient $c) use ($h, $item1, $item2, $cat1) {
                $r = $c->catalogCategories->replaceItems($cat1->id, [
                    new \nickdnk\Klaviyo\Resources\Shared\CatalogItem($item1->id),
                    new \nickdnk\Klaviyo\Resources\Shared\CatalogItem($item2->id),
                ]);
                $h->assert($r === null, 'void on success');
                $ids = $c->catalogCategories->itemIds($cat1->id);
                $h->assert(count($ids['data']) === 2, 'both items in cat1');

                return $r;
            });
    } else {
        foreach (['addCategories', 'removeCategories', 'replaceCategories'] as $m) {
            $h->skip('catalogItems', $m, 'item↔category linkage', 'second item or second category was not created');
        }
        foreach (['addItems', 'removeItems', 'replaceItems'] as $m) {
            $h->skip('catalogCategories', $m, 'category↔item linkage', 'second item or second category was not created');
        }
    }

    // ───────────────────────── Bulk jobs: items ─────────────────────────

    $bItemExt = [$h->name('bitem1'), $h->name('bitem2')];
    $bItemIds = array_map($cid, $bItemExt);

    $itemsReady = false;
    $itemCreateJob = $h->step('catalogItems', 'bulkCreate', 'bulk create 2 items (each w/ own categories rel) + fields[job]',
        fn(APIClient $c) => $c->catalogItems->bulkCreate(new BulkCreateCatalogItemsJob(array_map(
            function (string $ext) use ($h, $cat1) {
                $i = new CreateCatalogItem($ext, 'SDK Smoke Bulk Item ' . $ext, 'Bulk created.', 'https://example.com/products/' . $ext, '$custom', '$default', [$cat1->id]);
                $i->price = 9.99;
                $i->published = true;
                $i->custom_metadata = ['smokeRun' => $h->runId, 'bulk' => 'create'];

                return $i;
            },
            $bItemExt
        ))),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogItemBulkCreateJob && $r->id && in_array($r->status, ['queued', 'processing', 'complete'], true), 'job resource w/ status'));

    if ($itemCreateJob) {
        foreach ($bItemIds as $n => $id) {
            $h->cleanup('catalogItems', 'delete', 'bulk item ' . ($n + 1) . ' (safety net)', fn(APIClient $c) => $c->catalogItems->delete($id));
        }

        // Observed on this account: catalog-item bulk create jobs sit in `processing` for
        // minutes (4m25s in one run) while category / variant jobs finish in ~10-16s, so this
        // poll gets a much longer budget and the chained update/delete jobs wait for it.
        $h->step('catalogItems', 'getBulkCreateJob', 'poll bulk create job w/ fields[job] + fields[catalog-item] + include(items) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $itemCreateJob, $JOB_FIELDS) {
                $j = $c->catalogItems->getBulkCreateJob($itemCreateJob->id, (new Query())
                    ->fields('catalog-item-bulk-create-job', ...$JOB_FIELDS)
                    ->fields('catalog-item', 'title', 'price')
                    ->include('items'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 900, 10, 'catalog item bulk create job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->completed_count === 2 && $r->failed_count === 0 && $r->completed_at !== null, '2/2 created, 0 failed'));

        $itemsReady = $h->step('catalogItems', 'get', 'verify both bulk-created items exist',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $bItemIds) {
                foreach ($bItemIds as $id) {
                    if ($c->catalogItems->get($id) === null) {
                        return null;
                    }
                }

                return $c->catalogItems->get($bItemIds[0]);
            }, 300, 10, 'both bulk-created items readable'),
            fn($r) => $h->assert($r instanceof CatalogItem && $r->title === 'SDK Smoke Bulk Item ' . $bItemExt[0], 'bulk item created with the title we sent')) !== null;

        $h->step('catalogItems', 'getBulkCreateJobs', 'list bulk create jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogItems->getBulkCreateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-item-bulk-create-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1 && $r['data'][0]->status === 'complete', 'at least our job, all complete'));

        $h->skip('catalogItems', 'getBulkCreateJobs', 'list bulk create jobs w/ page[size]',
            'the bulk-job list endpoints declare only fields/filter/page[cursor]; probed once and Klaviyo answers 400 invalid: "\'page_size\' is not a valid field for the resource \'catalog-item-bulk-create-job\'" (pointer /data/attributes/page_size)');
    }

    $h->step('catalogItems', 'getBulkCreateJob', 'unknown bulk create job id → null',
        fn(APIClient $c) => $c->catalogItems->getBulkCreateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $itemUpdateJob = $h->step('catalogItems', 'bulkUpdate', 'bulk update 2 items (title/price/custom_metadata)',
        fn(APIClient $c) => $c->catalogItems->bulkUpdate(new BulkUpdateCatalogItemsJob(array_map(
            function (string $ext) use ($h, $cid) {
                $u = new UpdateCatalogItem($cid($ext));
                $u->title = 'SDK Smoke Bulk Item UPDATED ' . $ext;
                $u->price = 11.11;
                $u->custom_metadata = ['smokeRun' => $h->runId, 'bulk' => 'update'];

                return $u;
            },
            $bItemExt
        ))),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogItemBulkUpdateJob && $r->id, 'job resource'));

    if ($itemUpdateJob) {
        $h->step('catalogItems', 'getBulkUpdateJob', 'poll bulk update job w/ fields + include(items) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $itemUpdateJob, $JOB_FIELDS) {
                $j = $c->catalogItems->getBulkUpdateJob($itemUpdateJob->id, (new Query())
                    ->fields('catalog-item-bulk-update-job', ...$JOB_FIELDS)
                    ->fields('catalog-item', 'title', 'price')
                    ->include('items'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 300, 10, 'catalog item bulk update job complete'),
            fn($r) => $h->assert($r->total_count === 2 && (!$itemsReady || $r->failed_count === 0), '2 updated, 0 failed'));

        if ($itemsReady) {
            $h->step('catalogItems', 'get', 'verify bulk update landed (title + price changed)',
                fn(APIClient $c) => $h->waitFor(function () use ($c, $bItemIds, $bItemExt) {
                    $i = $c->catalogItems->get($bItemIds[1], (new Query())->fields('catalog-item', 'title', 'price', 'custom_metadata'));

                    return $i && $i->title === 'SDK Smoke Bulk Item UPDATED ' . $bItemExt[1] ? $i : null;
                }, 120, 10, 'bulk item update visible'),
                fn($r) => $h->assert($r instanceof CatalogItem && $r->price === 11.11 && ($r->custom_metadata['bulk'] ?? null) === 'update', 'bulk update applied'));
        } else {
            $h->skip('catalogItems', 'get', 'verify bulk update landed', 'the bulk create job had not materialised its items yet');
        }

        $h->step('catalogItems', 'getBulkUpdateJobs', 'list bulk update jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogItems->getBulkUpdateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-item-bulk-update-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogItems', 'getBulkUpdateJob', 'unknown bulk update job id → null',
        fn(APIClient $c) => $c->catalogItems->getBulkUpdateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $itemDeleteJob = $h->step('catalogItems', 'bulkDelete', 'bulk delete the 2 bulk-created items',
        fn(APIClient $c) => $c->catalogItems->bulkDelete(new BulkDeleteCatalogItemsJob($bItemIds)),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogItemBulkDeleteJob && $r->id, 'job resource'));

    if ($itemDeleteJob) {
        $h->step('catalogItems', 'getBulkDeleteJob', 'poll bulk delete job w/ fields[job] (spec: no include here) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $itemDeleteJob, $JOB_FIELDS) {
                $j = $c->catalogItems->getBulkDeleteJob($itemDeleteJob->id, (new Query())->fields('catalog-item-bulk-delete-job', ...$JOB_FIELDS));

                return $j && $j->status === 'complete' ? $j : null;
            }, 300, 10, 'catalog item bulk delete job complete'),
            fn($r) => $h->assert($r->total_count === 2 && (!$itemsReady || $r->failed_count === 0), '2 deleted, 0 failed'));

        if ($itemsReady) {
            $h->step('catalogItems', 'get', 'bulk-deleted item is gone → null',
                fn(APIClient $c) => $h->waitFor(fn() => $c->catalogItems->get($bItemIds[0]) === null ?: null, 120, 10, 'bulk item deletion visible'),
                fn($r) => $h->assert($r === true, 'null after bulk delete'));
        } else {
            $h->skip('catalogItems', 'get', 'verify bulk delete', 'the bulk create job had not materialised its items yet');
        }

        $h->step('catalogItems', 'getBulkDeleteJobs', 'list bulk delete jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogItems->getBulkDeleteJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-item-bulk-delete-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogItems', 'getBulkDeleteJob', 'unknown bulk delete job id → null',
        fn(APIClient $c) => $c->catalogItems->getBulkDeleteJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────────── Bulk jobs: categories ─────────────────────────

    $bCatExt = [$h->name('bcat1'), $h->name('bcat2')];
    $bCatIds = array_map($cid, $bCatExt);

    $catCreateJob = $h->step('catalogCategories', 'bulkCreate', 'bulk create 2 categories (one with an items rel)',
        fn(APIClient $c) => $c->catalogCategories->bulkCreate(new BulkCreateCatalogCategoriesJob([
            new CreateCatalogCategory($bCatExt[0], 'SDK Smoke Bulk Cat ' . $bCatExt[0], '$custom', '$default', [$item1->id]),
            new CreateCatalogCategory($bCatExt[1], 'SDK Smoke Bulk Cat ' . $bCatExt[1]),
        ])),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkCreateJob && $r->id, 'job resource'));

    if ($catCreateJob) {
        foreach ($bCatIds as $n => $id) {
            $h->cleanup('catalogCategories', 'delete', 'bulk category ' . ($n + 1) . ' (safety net)', fn(APIClient $c) => $c->catalogCategories->delete($id));
        }

        $h->step('catalogCategories', 'getBulkCreateJob', 'poll bulk create job w/ fields + include(categories) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $catCreateJob, $JOB_FIELDS) {
                $j = $c->catalogCategories->getBulkCreateJob($catCreateJob->id, (new Query())
                    ->fields('catalog-category-bulk-create-job', ...$JOB_FIELDS)
                    ->fields('catalog-category', 'name', 'external_id')
                    ->include('categories'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog category bulk create job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 created, 0 failed'));

        $h->step('catalogCategories', 'get', 'verify bulk-created category exists',
            fn(APIClient $c) => $c->catalogCategories->get($bCatIds[0]),
            fn($r) => $h->assert($r instanceof CatalogCategory && $r->name === 'SDK Smoke Bulk Cat ' . $bCatExt[0], 'created with the name we sent'));

        $h->step('catalogCategories', 'getBulkCreateJobs', 'list bulk create jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogCategories->getBulkCreateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-category-bulk-create-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogCategories', 'getBulkCreateJob', 'unknown bulk create job id → null',
        fn(APIClient $c) => $c->catalogCategories->getBulkCreateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $catUpdateJob = $h->step('catalogCategories', 'bulkUpdate', 'bulk update 2 category names',
        fn(APIClient $c) => $c->catalogCategories->bulkUpdate(new BulkUpdateCatalogCategoriesJob(array_map(
            function (string $ext) use ($cid) {
                $u = new UpdateCatalogCategory($cid($ext));
                $u->name = 'SDK Smoke Bulk Cat UPDATED ' . $ext;

                return $u;
            },
            $bCatExt
        ))),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkUpdateJob && $r->id, 'job resource'));

    if ($catUpdateJob) {
        $h->step('catalogCategories', 'getBulkUpdateJob', 'poll bulk update job w/ fields + include(categories) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $catUpdateJob, $JOB_FIELDS) {
                $j = $c->catalogCategories->getBulkUpdateJob($catUpdateJob->id, (new Query())
                    ->fields('catalog-category-bulk-update-job', ...$JOB_FIELDS)
                    ->fields('catalog-category', 'name')
                    ->include('categories'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog category bulk update job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 updated, 0 failed'));

        $h->step('catalogCategories', 'get', 'verify bulk update landed (name changed)',
            fn(APIClient $c) => $c->catalogCategories->get($bCatIds[1], (new Query())->fields('catalog-category', 'name')),
            fn($r) => $h->assert($r instanceof CatalogCategory && $r->name === 'SDK Smoke Bulk Cat UPDATED ' . $bCatExt[1], 'bulk update applied'));

        $h->step('catalogCategories', 'getBulkUpdateJobs', 'list bulk update jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogCategories->getBulkUpdateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-category-bulk-update-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogCategories', 'getBulkUpdateJob', 'unknown bulk update job id → null',
        fn(APIClient $c) => $c->catalogCategories->getBulkUpdateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $catDeleteJob = $h->step('catalogCategories', 'bulkDelete', 'bulk delete the 2 bulk-created categories',
        fn(APIClient $c) => $c->catalogCategories->bulkDelete(new BulkDeleteCatalogCategoriesJob($bCatIds)),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkDeleteJob && $r->id, 'job resource'));

    if ($catDeleteJob) {
        $h->step('catalogCategories', 'getBulkDeleteJob', 'poll bulk delete job w/ fields[job] until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $catDeleteJob, $JOB_FIELDS) {
                $j = $c->catalogCategories->getBulkDeleteJob($catDeleteJob->id, (new Query())->fields('catalog-category-bulk-delete-job', ...$JOB_FIELDS));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog category bulk delete job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 deleted, 0 failed'));

        $h->step('catalogCategories', 'get', 'bulk-deleted category is gone → null',
            fn(APIClient $c) => $c->catalogCategories->get($bCatIds[0]),
            fn($r) => $h->assert($r === null, 'null after bulk delete'));

        $h->step('catalogCategories', 'getBulkDeleteJobs', 'list bulk delete jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogCategories->getBulkDeleteJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-category-bulk-delete-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogCategories', 'getBulkDeleteJob', 'unknown bulk delete job id → null',
        fn(APIClient $c) => $c->catalogCategories->getBulkDeleteJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────────────── Bulk jobs: variants ─────────────────────────

    $bVarExt = [$h->name('bvar1'), $h->name('bvar2')];
    $bVarIds = array_map($cid, $bVarExt);

    $varCreateJob = $h->step('catalogVariants', 'bulkCreate', 'bulk create 2 variants on item1',
        fn(APIClient $c) => $c->catalogVariants->bulkCreate(new BulkCreateCatalogVariantsJob(array_map(
            function (string $ext) use ($h, $item1) {
                $v = new CreateCatalogVariant($ext, 'SDK Smoke Bulk Variant ' . $ext, 'Bulk created variant.', strtoupper(str_replace('sdk-smoke-', 'SKU-', $ext)), 1, 5.0, 7.77, 'https://example.com/products/' . $ext, $item1->id);
                $v->published = true;
                $v->custom_metadata = ['smokeRun' => $h->runId, 'bulk' => 'create'];

                return $v;
            },
            $bVarExt
        ))),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkCreateJob && $r->id, 'job resource'));

    if ($varCreateJob) {
        foreach ($bVarIds as $n => $id) {
            $h->cleanup('catalogVariants', 'delete', 'bulk variant ' . ($n + 1) . ' (safety net)', fn(APIClient $c) => $c->catalogVariants->delete($id));
        }

        $h->step('catalogVariants', 'getBulkCreateJob', 'poll bulk create job w/ fields + include(variants) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $varCreateJob, $JOB_FIELDS) {
                $j = $c->catalogVariants->getBulkCreateJob($varCreateJob->id, (new Query())
                    ->fields('catalog-variant-bulk-create-job', ...$JOB_FIELDS)
                    ->fields('catalog-variant', 'sku', 'price')
                    ->include('variants'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog variant bulk create job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 created, 0 failed'));

        $h->step('catalogVariants', 'get', 'verify bulk-created variant exists',
            fn(APIClient $c) => $c->catalogVariants->get($bVarIds[0]),
            fn($r) => $h->assert($r instanceof CatalogVariant && (float)$r->price === 7.77, 'created with the price we sent'));

        $h->step('catalogItems', 'variantIds', 'item1 now has 4 variants (2 sync + 2 bulk)',
            fn(APIClient $c) => $c->catalogItems->variantIds($item1->id, (new Query())->pageSize(100)),
            fn($r) => $h->assert(count($r['data']) === 4, '4 variants under item1'));

        $h->step('catalogVariants', 'getBulkCreateJobs', 'list bulk create jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogVariants->getBulkCreateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-variant-bulk-create-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogVariants', 'getBulkCreateJob', 'unknown bulk create job id → null',
        fn(APIClient $c) => $c->catalogVariants->getBulkCreateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $varUpdateJob = $h->step('catalogVariants', 'bulkUpdate', 'bulk update 2 variants (title/price/inventory)',
        fn(APIClient $c) => $c->catalogVariants->bulkUpdate(new BulkUpdateCatalogVariantsJob(array_map(
            function (string $ext) use ($cid) {
                $u = new UpdateCatalogVariant($cid($ext));
                $u->title = 'SDK Smoke Bulk Variant UPDATED ' . $ext;
                $u->price = 8.88;
                $u->inventory_quantity = 99.0;
                $u->inventory_policy = 2;

                return $u;
            },
            $bVarExt
        ))),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkUpdateJob && $r->id, 'job resource'));

    if ($varUpdateJob) {
        $h->step('catalogVariants', 'getBulkUpdateJob', 'poll bulk update job w/ fields + include(variants) until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $varUpdateJob, $JOB_FIELDS) {
                $j = $c->catalogVariants->getBulkUpdateJob($varUpdateJob->id, (new Query())
                    ->fields('catalog-variant-bulk-update-job', ...$JOB_FIELDS)
                    ->fields('catalog-variant', 'title', 'price')
                    ->include('variants'));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog variant bulk update job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 updated, 0 failed'));

        $h->step('catalogVariants', 'get', 'verify bulk update landed (title + price changed)',
            fn(APIClient $c) => $c->catalogVariants->get($bVarIds[1], (new Query())->fields('catalog-variant', 'title', 'price', 'inventory_quantity')),
            fn($r) => $h->assert($r instanceof CatalogVariant && $r->title === 'SDK Smoke Bulk Variant UPDATED ' . $bVarExt[1] && (float)$r->price === 8.88, 'bulk update applied'));

        $h->step('catalogVariants', 'getBulkUpdateJobs', 'list bulk update jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogVariants->getBulkUpdateJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-variant-bulk-update-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogVariants', 'getBulkUpdateJob', 'unknown bulk update job id → null',
        fn(APIClient $c) => $c->catalogVariants->getBulkUpdateJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ── back in stock before the variants are deleted: needs a live, out-of-stock-able variant
    if ($var2) {
        $bisEmail = $h->email('bis');
        $h->step('backInStockSubscriptions', 'create', 'back-in-stock subscription for variant 2, channels=[EMAIL]',
            function (APIClient $c) use ($bisEmail, $var2) {
                $p = new ImportProfile();
                $p->email = $bisEmail;

                return $c->backInStockSubscriptions->create(new CreateBackInStockSubscription(['EMAIL'], $p, $var2->id));
            },
            fn($r) => $h->assert($r === null, '202 with empty body → null'));
        $h->mutation("Back-in-stock subscription created for {$bisEmail} on variant {$var2->id}; Klaviyo exposes no read/delete endpoint for BIS subscriptions, and the profile it created cannot be deleted from this suite (no ProfileService access here) — needs a data-privacy deletion job for {$bisEmail}.");
    } else {
        $h->skip('backInStockSubscriptions', 'create', 'back-in-stock subscription', 'no variant available to subscribe to');
    }

    $varDeleteJob = $h->step('catalogVariants', 'bulkDelete', 'bulk delete the 2 bulk-created variants',
        fn(APIClient $c) => $c->catalogVariants->bulkDelete(new BulkDeleteCatalogVariantsJob($bVarIds)),
        fn($r) => $h->assert($r instanceof \nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkDeleteJob && $r->id, 'job resource'));

    if ($varDeleteJob) {
        $h->step('catalogVariants', 'getBulkDeleteJob', 'poll bulk delete job w/ fields[job] until complete',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $varDeleteJob, $JOB_FIELDS) {
                $j = $c->catalogVariants->getBulkDeleteJob($varDeleteJob->id, (new Query())->fields('catalog-variant-bulk-delete-job', ...$JOB_FIELDS));

                return $j && $j->status === 'complete' ? $j : null;
            }, 600, 10, 'catalog variant bulk delete job complete'),
            fn($r) => $h->assert($r->total_count === 2 && $r->failed_count === 0, '2 deleted, 0 failed'));

        $h->step('catalogVariants', 'get', 'bulk-deleted variant is gone → null',
            fn(APIClient $c) => $c->catalogVariants->get($bVarIds[0]),
            fn($r) => $h->assert($r === null, 'null after bulk delete'));

        $h->step('catalogVariants', 'getBulkDeleteJobs', 'list bulk delete jobs w/ filter equals(status,"complete") + fields[job]',
            fn(APIClient $c) => $c->catalogVariants->getBulkDeleteJobs((new Query())
                ->filter(Filter::equals('status', 'complete'))
                ->fields('catalog-variant-bulk-delete-job', ...$JOB_FIELDS)),
            fn($r) => $h->assert(count($r['data']) >= 1, 'jobs'));
    }

    $h->step('catalogVariants', 'getBulkDeleteJob', 'unknown bulk delete job id → null',
        fn(APIClient $c) => $c->catalogVariants->getBulkDeleteJob('sdk-smoke-nope-' . $h->runId),
        fn($r) => $h->assert($r === null, 'null on 404'));

    // ───────────────── returnRequest + executePool ─────────────────

    $h->step('catalogItems', 'executePool', 'executePool: 3 catalog GETs via returnRequest (one 404)',
        function (APIClient $c) use ($h, $item1, $cat1, $cid) {
            $reqs = [
                $c->catalogItems->get($item1->id, (new Query())->fields('catalog-item', 'title'), returnRequest: true),
                $c->catalogItems->get($cid('sdk-smoke-nope-pool'), returnRequest: true),
                $c->catalogCategories->get($cat1->id, returnRequest: true),
            ];
            $h->assert($reqs[0] instanceof \Psr\Http\Message\RequestInterface, 'returnRequest gives a PSR-7 request');
            $res = $c->executePool($reqs, 3);
            $h->assert(count($res) === 3, '3 results');
            $h->assert($res[0] instanceof CatalogItem && $res[0]->id === $item1->id, 'item ok');
            $h->assert($res[1] instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $res[1]->getHttpStatus() === 404, '404 surfaces as exception');
            $h->assert($res[2] instanceof CatalogCategory && $res[2]->id === $cat1->id, 'category ok');

            return $res;
        });

    // ───────────────── deletes exercised in-suite (rest in cleanup) ─────────────────

    if ($var1) {
        $h->step('catalogVariants', 'delete', 'delete variant 1 directly, verify get() → null',
            function (APIClient $c) use ($h, $var1) {
                $r = $c->catalogVariants->delete($var1->id);
                $h->assert($r === null, 'void');
                $h->assert($c->catalogVariants->get($var1->id) === null, 'gone');

                return $r;
            });
        unset($variants[$h->name('item1-v1')]);
    }

    if ($cat2) {
        $h->step('catalogCategories', 'delete', 'delete cat2 directly, verify get() → null (items survive)',
            function (APIClient $c) use ($h, $cat2, $item2) {
                $r = $c->catalogCategories->delete($cat2->id);
                $h->assert($r === null, 'void');
                $h->assert($c->catalogCategories->get($cat2->id) === null, 'gone');
                if ($item2) {
                    $h->assert($c->catalogItems->get($item2->id) !== null, 'deleting a category does not delete its items');
                }

                return $r;
            });
    }

    if ($item2) {
        $h->step('catalogItems', 'delete', 'delete item2 directly, verify get() → null and its variants went with it',
            function (APIClient $c) use ($h, $item2, $variants) {
                $r = $c->catalogItems->delete($item2->id);
                $h->assert($r === null, 'void');
                $h->assert($c->catalogItems->get($item2->id) === null, 'gone');
                $left = $c->catalogVariants->list((new Query())->filter(Filter::equals('item.id', $item2->id)));
                $h->assert(count($left['data']) === 0, 'variants of a deleted item are gone too');

                return $r;
            });
    }

    $h->step('catalogItems', 'delete', 'delete unknown item id (SDK swallows 404)',
        fn(APIClient $c) => $c->catalogItems->delete($cid('sdk-smoke-nope-' . $h->runId)),
        fn($r) => $h->assert($r === null, 'void, no exception on 404'));

    $h->note('POST {resource}/relationships/{relation} is NOT idempotent on catalogs: re-adding an existing item↔category link answers 409 conflict "One or more of the relations specified for the item with id `…` already exists." (pointer /data/id). The suite removes before adding.');
    $h->note('Catalog bulk-job latency on this account is very uneven: catalog-category and catalog-variant jobs report `complete` in ~10-20s, while the catalog-item bulk create job sat in `processing` for 4m25s in one run (items became readable ~4.5 min after submit, before the job flipped to complete). A chained bulk update/delete submitted against those ids before then completes with failed_count=2 and per-id errors "An item with the id `…` does not exist." — so callers must poll the create job (and, to be safe, the items) before chaining.');
    $h->note('Spec knobs deliberately not sent: `sort` on any catalog collection accepts only `created`/`-created`, so no `-title`; `include` is unavailable on GET /catalog-variants(/{id}), GET /catalog-categories(/{id}) and on the bulk *delete* job GETs; the bulk job list endpoints declare only fields/filter/page[cursor] (no page[size], no sort).');
    $h->note('The SDK drops the JSON:API `included` array on single-resource reads: get()/create()/update() return `$result[\'data\']` only, so an `include=variants` on GET /catalog-items/{id} yields relationship identifiers but no hydrated sideloaded resources. list() keeps `included` in the returned array but leaves it as raw decoded arrays (not hydrated into response classes).');
};
