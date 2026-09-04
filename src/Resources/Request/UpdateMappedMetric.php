<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CustomMetric;
use nickdnk\Klaviyo\Resources\Shared\MappedMetric;
use nickdnk\Klaviyo\Resources\Shared\Metric;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * PATCH /api/mapped-metrics/{id}. The id is one of Klaviyo's fixed mapping slots —
 * `added_to_cart`, `cancelled_sales`, `ordered_product`, `refunded_sales`, `revenue`,
 * `started_checkout`, `viewed_product` — and the body points that slot at a metric or a
 * custom metric through relationships; the body carries no attributes.
 */
class UpdateMappedMetric extends MappedMetric
{

    public function __construct(string $id, ?string $metricId = null, ?string $customMetricId = null)
    {

        parent::__construct($id);

        if ($metricId !== null) {
            $this->addRelationship('metric', new Relationship(new Metric($metricId)));
        }
        if ($customMetricId !== null) {
            $this->addRelationship('custom-metric', new Relationship(new CustomMetric($customMetricId)));
        }
    }

    /**
     * Clears the mapping (`relationships.metric.data = null`), leaving the slot unmapped. This
     * also drops a custom-metric mapping; Klaviyo rejects `{"type":"metric","id":null}`, so the
     * whole `data` member has to be null.
     */
    public static function unset(string $id): self
    {

        $u = new self($id);
        $u->addRelationship('metric', new Relationship(null));

        return $u;
    }

}
