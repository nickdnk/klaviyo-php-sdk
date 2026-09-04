<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-item-bulk-create-jobs: up to 100 catalog items per job, 5MB per payload,
 * at most 500 jobs in progress at once. Each embedded item keeps its own optional
 * `categories` relationship.
 *
 * @property array{data: CreateCatalogItem[]} $items
 */
class BulkCreateCatalogItemsJob extends TypedResource
{

    /**
     * @param CreateCatalogItem[] $items
     */
    public function __construct(array $items)
    {

        $this->items = CreateCatalogItem::wrapDataMany(array_values($items));

    }

    public static function type(): string
    {

        return 'catalog-item-bulk-create-job';
    }

}
