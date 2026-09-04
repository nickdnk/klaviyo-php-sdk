<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogCategoryBulkDeleteJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-category-bulk-delete-job';
    }
}
