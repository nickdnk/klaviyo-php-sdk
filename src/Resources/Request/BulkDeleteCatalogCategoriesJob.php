<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategory;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-category-bulk-delete-jobs: up to 100 categories per job, 5MB maximum
 * payload, at most 500 jobs in progress at once. Each embedded category is an identifier
 * only — `type` and the composite category id, with no attributes.
 *
 * @property array{data: CatalogCategory[]} $categories
 */
class BulkDeleteCatalogCategoriesJob extends TypedResource
{

    /**
     * @param string[] $categoryIds composite category ids, e.g. `$custom:::$default:::SUMMER`
     */
    public function __construct(array $categoryIds)
    {

        $this->categories = CatalogCategory::wrapDataMany(
            array_map(fn(string $categoryId) => new CatalogCategory($categoryId), array_values($categoryIds))
        );
    }

    public static function type(): string
    {

        return 'catalog-category-bulk-delete-job';
    }

}
