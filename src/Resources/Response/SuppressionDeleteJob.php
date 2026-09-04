<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;

/**
 * @property string      $status  cancelled|complete|processing|queued
 * @property string      $created_at
 * @property int         $total_count
 * @property int|null    $completed_count
 * @property string|null $completed_at
 * @property int|null    $skipped_count
 */
class SuppressionDeleteJob extends IdentifiableResource
{

    public static function type(): string
    {

        return 'profile-suppression-bulk-delete-job';
    }
}
