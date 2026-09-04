<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * @property array{data: BulkCreateEvents[]} $events-bulk-create
 */
class BulkCreateEventsJob extends TypedResource
{

    public static function type(): string
    {

        return 'event-bulk-create-job';
    }

    /**
     * @param BulkCreateEvents[] $events
     */
    public function __construct(array $events)
    {

        $this->{'events-bulk-create'} = BulkCreateEvents::wrapDataMany($events);
    }

}
