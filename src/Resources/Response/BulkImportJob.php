<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;

/**
 * @property string      $status  cancelled|complete|processing|queued
 * @property string      $created_at
 * @property int         $total_count
 * @property int|null    $completed_count
 * @property int|null    $failed_count
 * @property string|null $completed_at
 * @property string|null $expires_at
 * @property string|null $started_at
 */
class BulkImportJob extends IdentifiableResource
{

    public static function type(): string
    {

        return 'profile-bulk-import-job';
    }
}
