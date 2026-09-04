<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-variant-bulk-create-jobs: up to 100 catalog variants per job, 5MB per
 * payload, at most 500 jobs in progress at once. Each embedded variant keeps its own required
 * `item` relationship, so one job can spread variants across several items.
 *
 * @property array{data: CreateCatalogVariant[]} $variants
 */
class BulkCreateCatalogVariantsJob extends TypedResource
{

    /**
     * @param CreateCatalogVariant[] $variants
     */
    public function __construct(array $variants)
    {

        $this->variants = CreateCatalogVariant::wrapDataMany(array_values($variants));

    }

    public static function type(): string
    {

        return 'catalog-variant-bulk-create-job';
    }

}
