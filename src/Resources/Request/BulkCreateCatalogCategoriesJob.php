<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-category-bulk-create-jobs: up to 100 categories per job, 5MB maximum
 * payload, at most 500 jobs in progress at once. Each embedded category carries the same
 * attributes and `items` relationship as a synchronous {@see CreateCatalogCategory}.
 *
 * @property array{data: CreateCatalogCategory[]} $categories
 */
class BulkCreateCatalogCategoriesJob extends TypedResource
{

    /**
     * @param CreateCatalogCategory[] $categories
     */
    public function __construct(array $categories)
    {

        $this->categories = CreateCatalogCategory::wrapDataMany(array_values($categories));
    }

    public static function type(): string
    {

        return 'catalog-category-bulk-create-job';
    }

}
