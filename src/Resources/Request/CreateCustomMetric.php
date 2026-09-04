<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/custom-metrics. `definition` groups one or more source metrics; each group
 * names the metric it aggregates and, optionally, the event property to sum and the
 * filters that narrow which events count. The linked metrics are derived by Klaviyo from
 * `definition.metric_groups[].metric_id`; the endpoint accepts no `relationships` member.
 *
 * @property string $name
 * @property array{aggregation_method: string, metric_groups: list<array{metric_id: string, metric_filters?: array[], value_property?: string|null}>} $definition
 */
class CreateCustomMetric extends TypedResource
{

    public static function type(): string
    {

        return 'custom-metric';
    }

    /**
     * @param string                                                                                      $aggregationMethod count|value
     * @param list<array{metric_id: string, metric_filters?: array[], value_property?: string|null}> $metricGroups
     */
    public function __construct(string $name, string $aggregationMethod, array $metricGroups)
    {

        $this->name = $name;
        $this->definition = ['aggregation_method' => $aggregationMethod, 'metric_groups' => array_values($metricGroups)];
    }

}
