<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/metric-aggregates. Aggregates the events behind one metric into buckets.
 * Klaviyo requires at least one `datetime` clause in `filter` to bound the query range,
 * e.g. `greater-or-equal(datetime,2024-01-01T00:00:00)`.
 *
 * @property string        $metric_id
 * @property string[]      $measurements   count|sum_value|unique
 * @property string|null   $interval       day|hour|month|week
 * @property string[]|null $filter         Klaviyo filter clauses, at least one on `datetime`
 * @property string[]|null $by             event properties to group by, e.g. `$flow_channel`, `Campaign Name`
 * @property string[]|null $return_fields
 * @property string|null   $timezone       IANA name; defaults to the account timezone
 * @property string|null   $sort           a grouping or measurement name, `-` prefixed for descending
 * @property int|null      $page_size
 * @property string|null   $page_cursor
 */
class MetricAggregateQuery extends TypedResource
{

    /**
     * @param string[] $measurements count|sum_value|unique
     */
    public function __construct(string $metricId, array $measurements)
    {

        $this->metric_id = $metricId;
        $this->measurements = array_values($measurements);
    }

    public static function type(): string
    {

        return 'metric-aggregate';
    }

}
