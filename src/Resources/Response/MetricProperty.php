<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\MetricProperty as SharedMetricProperty;

/**
 * @property string|null $label
 * @property string|null $property
 * @property string|null $inferred_type
 * @property array<int, int|float|string|bool>|null $sample_values  returned only when asked for
 *                                                                  via `additional-fields[metric-property]`
 */
class MetricProperty extends SharedMetricProperty
{

}
