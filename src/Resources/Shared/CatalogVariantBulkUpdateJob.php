<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogVariantBulkUpdateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-variant-bulk-update-job';
    }
}
