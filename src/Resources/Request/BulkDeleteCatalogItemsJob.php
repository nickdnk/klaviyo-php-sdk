<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-item-bulk-delete-jobs: up to 100 catalog items per job, 5MB per payload,
 * at most 500 jobs in progress at once. The embedded items are identifiers only, so the job
 * is built straight from the composite item ids.
 *
 * @property array{data: CatalogItem[]} $items
 */
class BulkDeleteCatalogItemsJob extends TypedResource
{

    /**
     * @param string[] $itemIds
     */
    public function __construct(array $itemIds)
    {

        $this->items = CatalogItem::wrapDataMany(array_map(
            static fn(string $itemId) => new CatalogItem($itemId),
            array_values($itemIds)
        ));

    }

    public static function type(): string
    {

        return 'catalog-item-bulk-delete-job';
    }

}
