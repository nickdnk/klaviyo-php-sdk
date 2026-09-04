<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogCategoryBulkCreateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-category-bulk-create-job';
    }
}
