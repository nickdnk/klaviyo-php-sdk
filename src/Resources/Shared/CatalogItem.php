<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CatalogItem extends IdentifiableResource
{
    public static function type(): string
    {

        return 'catalog-item';
    }
}
