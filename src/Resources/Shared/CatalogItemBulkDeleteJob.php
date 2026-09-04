<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogItemBulkDeleteJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-item-bulk-delete-job';
    }
}
