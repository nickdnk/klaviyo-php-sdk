<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-category-bulk-update-jobs: up to 100 categories per job, 5MB maximum
 * payload, at most 500 jobs in progress at once. Each embedded category names its id and
 * the attributes and `items` relationship that change, exactly as a synchronous
 * {@see UpdateCatalogCategory} does.
 *
 * @property array{data: UpdateCatalogCategory[]} $categories
 */
class BulkUpdateCatalogCategoriesJob extends TypedResource
{

    /**
     * @param UpdateCatalogCategory[] $categories
     */
    public function __construct(array $categories)
    {

        $this->categories = UpdateCatalogCategory::wrapDataMany(array_values($categories));
    }

    public static function type(): string
    {

        return 'catalog-category-bulk-update-job';
    }

}
