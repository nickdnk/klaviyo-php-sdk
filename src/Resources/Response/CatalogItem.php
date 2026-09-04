<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CatalogItem as SharedCatalogItem;

/**
 * @property string|null $external_id
 * @property string|null $title
 * @property string|null $description
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
class CatalogItem extends SharedCatalogItem
{

}
