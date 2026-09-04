<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-item-bulk-update-jobs: up to 100 catalog items per job, 5MB per payload,
 * at most 500 jobs in progress at once. Each embedded item carries its composite id and only
 * the attributes that change.
 *
 * @property array{data: UpdateCatalogItem[]} $items
 */
class BulkUpdateCatalogItemsJob extends TypedResource
{

    /**
     * @param UpdateCatalogItem[] $items
     */
    public function __construct(array $items)
    {

        $this->items = UpdateCatalogItem::wrapDataMany(array_values($items));

    }

    public static function type(): string
    {

        return 'catalog-item-bulk-update-job';
    }

}
