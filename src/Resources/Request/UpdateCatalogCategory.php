<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategory;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * PATCH /api/catalog-categories/{id}. `external_id`, `integration_type` and `catalog_type`
 * are fixed at creation, so `name` is the only attribute that can change. Item ids given
 * here replace the category's whole item set; the relationship trio on
 * {@see \nickdnk\Klaviyo\Services\CatalogCategoryService::addItems()} adds or
 * removes individual items instead.
 *
 * @property string|null $name
 */
class UpdateCatalogCategory extends CatalogCategory
{

    /**
     * @param string[]|null $itemIds catalog item ids that make up the new item set
     */
    public function __construct(string $id, ?array $itemIds = null)
    {

        parent::__construct($id);

        if ($itemIds) {
            $this->addRelationship(
                'items',
                new Relationship(array_map(fn(string $itemId) => new CatalogItem($itemId), array_values($itemIds)))
            );
        }
    }

}
