<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogCategoryBulkUpdateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-category-bulk-update-job';
    }
}
