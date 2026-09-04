<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategory;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * PATCH /api/catalog-items/{id}: every attribute is optional, so only the ones set are sent.
 * The same shape is embedded per item in {@see BulkUpdateCatalogItemsJob}.
 *
 * `external_id`, `integration_type` and `catalog_type` make up the id and cannot be changed.
 * A `categories` relationship replaces the item's full category set.
 *
 * @property string|null $title
 * @property string|null $description
 * @property string|null $url
 * @property float|null  $price
 * @property string|null $image_full_url
 * @property string|null $image_thumbnail_url
 * @property array|null  $images               image URLs
 * @property array|null  $custom_metadata
 * @property bool|null   $published
 */
class UpdateCatalogItem extends CatalogItem
{

    /**
     * @param string[] $categoryIds catalog category ids that make up the item's new category set
     */
    public function __construct(string $id, array $categoryIds = [])
    {

        parent::__construct($id);

        if ($categoryIds) {
            $this->setCategories($categoryIds);
        }

    }

    /**
     * Replaces the `categories` relationship with the given category ids.
     *
     * @param string[] $categoryIds
     */
    public function setCategories(array $categoryIds): void
    {

        $this->addRelationship('categories', new Relationship(array_map(
            static fn(string $categoryId) => new CatalogCategory($categoryId),
            array_values($categoryIds)
        )));

    }

}
