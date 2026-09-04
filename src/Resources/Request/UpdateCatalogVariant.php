<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogVariant;

/**
 * PATCH /api/catalog-variants/{id}: every attribute is optional, so only the ones set are
 * sent. The same shape is embedded per variant in {@see BulkUpdateCatalogVariantsJob}.
 *
 * `external_id`, `integration_type` and `catalog_type` make up the id and cannot be changed,
 * and the variant stays with the item it was created under.
 *
 * @property string|null $title
 * @property string|null $description
 * @property string|null $sku
 * @property int|null    $inventory_policy     0 (ignore) | 1 (deny out of stock) | 2 (continue)
 * @property float|null  $inventory_quantity
 * @property float|null  $price
 * @property string|null $url
 * @property string|null $image_full_url
 * @property string|null $image_thumbnail_url
 * @property array|null  $images               image URLs
 * @property array|null  $custom_metadata
 * @property bool|null   $published
 */
class UpdateCatalogVariant extends CatalogVariant
{

    public function __construct(string $id)
    {

        parent::__construct($id);

    }

}
