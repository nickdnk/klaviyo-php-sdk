<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CustomMetric;

/**
 * PATCH /api/custom-metrics/{id}. Set only the attributes that change; `definition` is
 * replaced wholesale when given, so it must carry both `aggregation_method` and the full
 * `metric_groups` list. The endpoint accepts no `relationships` member; the linked metrics
 * follow from the definition.
 *
 * @property string|null $name
 * @property array{aggregation_method: string, metric_groups: list<array{metric_id: string, metric_filters?: array[], value_property?: string|null}>}|null $definition
 */
class UpdateCustomMetric extends CustomMetric
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
