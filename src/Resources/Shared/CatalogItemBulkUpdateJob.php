<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogItemBulkUpdateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-item-bulk-update-job';
    }
}
