<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogVariantBulkDeleteJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-variant-bulk-delete-job';
    }
}
