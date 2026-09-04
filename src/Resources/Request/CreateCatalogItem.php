<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategory;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/catalog-items: one item created synchronously. The same shape is embedded per
 * item in {@see BulkCreateCatalogItemsJob}.
 *
 * The item's Klaviyo id is composed from integration type, catalog type and external id
 * (`$custom:::$default:::SAMPLE-DATA-ITEM-1`), so both defaults feed the id the API answers
 * with. `$custom` / `$default` are currently the only pair Klaviyo supports.
 *
 * The optional `categories` relationship links the item into existing catalog categories.
 *
 * @property string      $external_id
 * @property string      $title
 * @property string      $description
 * @property string      $url
 * @property string      $integration_type
 * @property string|null $catalog_type
 * @property float|null  $price
 * @property string|null $image_full_url
 * @property string|null $image_thumbnail_url
 * @property array|null  $images               image URLs
 * @property array|null  $custom_metadata
 * @property bool|null   $published
 */
class CreateCatalogItem extends CatalogItem
{

    /**
     * @param string[] $categoryIds catalog category ids to link the item into
     */
    public function __construct(string $externalId, string $title, string $description, string $url,
        string $integrationType = '$custom', string $catalogType = '$default', array $categoryIds = []
    )
    {

        parent::__construct();
        $this->external_id = $externalId;
        $this->title = $title;
        $this->description = $description;
        $this->url = $url;
        $this->integration_type = $integrationType;
        $this->catalog_type = $catalogType;

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
