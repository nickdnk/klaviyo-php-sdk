<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Metric as SharedMetric;

/**
 * @property string      $name
 * @property string|null $service
 */
class Metric extends SharedMetric
{

    public function __construct(string $name)
    {

        parent::__construct();
        $this->name = $name;
    }

}
