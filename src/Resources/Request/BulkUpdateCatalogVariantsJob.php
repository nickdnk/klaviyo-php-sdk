<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-variant-bulk-update-jobs: up to 100 catalog variants per job, 5MB per
 * payload, at most 500 jobs in progress at once. Each embedded variant carries its composite
 * id and only the attributes that change.
 *
 * @property array{data: UpdateCatalogVariant[]} $variants
 */
class BulkUpdateCatalogVariantsJob extends TypedResource
{

    /**
     * @param UpdateCatalogVariant[] $variants
     */
    public function __construct(array $variants)
    {

        $this->variants = UpdateCatalogVariant::wrapDataMany(array_values($variants));

    }

    public static function type(): string
    {

        return 'catalog-variant-bulk-update-job';
    }

}
