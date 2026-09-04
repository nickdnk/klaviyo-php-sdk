<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategoryBulkUpdateJob as SharedCatalogCategoryBulkUpdateJob;

/**
 * @property string|null $status           cancelled|complete|processing|queued
 * @property string|null $created_at
 * @property int|null    $total_count
 * @property int|null    $completed_count
 * @property int|null    $failed_count
 * @property string|null $completed_at
 * @property array|null  $errors
 * @property string|null $expires_at
 */
class CatalogCategoryBulkUpdateJob extends SharedCatalogCategoryBulkUpdateJob
{

}
