<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\CatalogVariant;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/catalog-variants: one variant of an existing catalog item. The same shape is
 * embedded per variant in {@see BulkCreateCatalogVariantsJob}.
 *
 * The `item` relationship is required and names the parent item by its composite id
 * (`$custom:::$default:::SAMPLE-DATA-ITEM-1`); the variant's own id is composed the same way
 * from integration type, catalog type and external id.
 *
 * @property string      $external_id
 * @property string      $title
 * @property string      $description
 * @property string      $sku
 * @property int         $inventory_policy     0 (ignore) | 1 (deny out of stock) | 2 (continue)
 * @property float       $inventory_quantity
 * @property float       $price
 * @property string      $url
 * @property string      $integration_type
 * @property string|null $catalog_type
 * @property string|null $image_full_url
 * @property string|null $image_thumbnail_url
 * @property array|null  $images               image URLs
 * @property array|null  $custom_metadata
 * @property bool|null   $published
 */
class CreateCatalogVariant extends CatalogVariant
{

    public function __construct(string $externalId, string $title, string $description, string $sku,
        int $inventoryPolicy, float $inventoryQuantity, float $price, string $url, string $itemId,
        string $integrationType = '$custom', string $catalogType = '$default'
    )
    {

        parent::__construct();
        $this->external_id = $externalId;
        $this->title = $title;
        $this->description = $description;
        $this->sku = $sku;
        $this->inventory_policy = $inventoryPolicy;
        $this->inventory_quantity = $inventoryQuantity;
        $this->price = $price;
        $this->url = $url;
        $this->integration_type = $integrationType;
        $this->catalog_type = $catalogType;
        $this->addRelationship('item', new Relationship(new CatalogItem($itemId)));

    }

}
