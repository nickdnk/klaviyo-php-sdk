<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Event as SharedEvent;

/**
 * @property int|null          $timestamp
 * @property AttributeBag|null $event_properties
 * @property string|null       $datetime
 * @property string|null       $uuid
 */
class Event extends SharedEvent
{

    protected static function nested(): array
    {

        return ['event_properties' => AttributeBag::class];
    }
}
