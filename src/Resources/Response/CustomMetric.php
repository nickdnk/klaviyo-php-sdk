<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CustomMetric as SharedCustomMetric;

/**
 * @property string|null $name
 * @property string|null $created
 * @property string|null $updated
 * @property array|null  $definition  {aggregation_method,metric_groups}
 */
class CustomMetric extends SharedCustomMetric
{

}
