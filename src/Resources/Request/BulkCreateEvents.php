<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * @property array{data: CreateProfile} $profile
 * @property array{data: CreateEvent[]} $events
 */
class BulkCreateEvents extends TypedResource
{

    public static function type(): string
    {

        return 'event-bulk-create';
    }

    /**
     * @param CreateProfile $profile
     * @param CreateEvent[] $events
     */
    public function __construct(CreateProfile $profile, array $events)
    {

        $this->profile = $profile->wrapData();
        $this->events = CreateEvent::wrapDataMany($events);
    }

}
