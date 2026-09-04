<?php


namespace nickdnk\Klaviyo\Resources\Request;


/**
 * @property array{data: ImportProfile} $profile
 */
class CreateEventForProfile extends CreateEvent
{

    public function __construct(Metric $metric, ImportProfile $profile, array $properties)
    {

        parent::__construct($metric, $properties);
        $this->profile = $profile->wrapData();
    }

}
