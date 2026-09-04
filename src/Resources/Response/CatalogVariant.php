<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CatalogVariant as SharedCatalogVariant;

/**
 * @property string|null $external_id
 * @property string|null $title
 * @property string|null $description
 * @property string|null $sku
 * @property int|null    $inventory_policy  0 = continue selling when out of stock, 1 = stop, 2 = deny (spec enum)
 * @property int|float|null $inventory_quantity  spec says number; the API returns an int for whole quantities
 * @property float|null  $price
 * @property string|null $url
 * @property string|null $image_full_url
 * @property string|null $image_thumbnail_url
 * @property array|null  $images
 * @property array|null  $custom_metadata
 * @property bool|null   $published
 * @property string|null $created
 * @property string|null $updated
 */
class CatalogVariant extends SharedCatalogVariant
{

}
