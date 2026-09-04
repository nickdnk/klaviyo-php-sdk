<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Event;

/**
 * @property array               $properties
 * @property array{data: Metric} $metric
 * @property string|null         $unique_id
 * @property float|null          $value
 * @property string|null         $value_currency
 * @property string|null         $time  ISO 8601
 */
class CreateEvent extends Event
{

    public function __construct(Metric $metric, array $properties)
    {

        parent::__construct();
        $this->metric = $metric->wrapData();
        $this->properties = $properties;
    }

}
