<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogItemBulkCreateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-item-bulk-create-job';
    }
}
