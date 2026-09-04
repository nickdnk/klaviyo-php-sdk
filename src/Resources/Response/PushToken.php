<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\DeviceMetadata;
use nickdnk\Klaviyo\Resources\Shared\PushToken as SharedPushToken;

/**
 * @property string|null         $created
 * @property string|null         $recorded_date
 * @property DeviceMetadata|null $metadata
 */
class PushToken extends SharedPushToken
{

    protected static function nested(): array
    {

        return ['metadata' => DeviceMetadata::class];
    }
}
