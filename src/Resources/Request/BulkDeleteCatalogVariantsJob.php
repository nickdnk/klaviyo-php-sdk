<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogVariant;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/catalog-variant-bulk-delete-jobs: up to 100 catalog variants per job, 5MB per
 * payload, at most 500 jobs in progress at once. The embedded variants are identifiers only,
 * so the job is built straight from the composite variant ids.
 *
 * @property array{data: CatalogVariant[]} $variants
 */
class BulkDeleteCatalogVariantsJob extends TypedResource
{

    /**
     * @param string[] $variantIds
     */
    public function __construct(array $variantIds)
    {

        $this->variants = CatalogVariant::wrapDataMany(array_map(
            static fn(string $variantId) => new CatalogVariant($variantId),
            array_values($variantIds)
        ));

    }

    public static function type(): string
    {

        return 'catalog-variant-bulk-delete-job';
    }

}
