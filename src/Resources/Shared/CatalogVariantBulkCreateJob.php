<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogVariantBulkCreateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-variant-bulk-create-job';
    }
}
