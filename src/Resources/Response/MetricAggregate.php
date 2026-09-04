<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\MetricAggregate as SharedMetricAggregate;

/**
 * One row per `by` combination; each measurement is a list parallel to `dates`, so
 * `$aggregate->data[0]['measurements']['count'][2]` is the count in the third bucket.
 *
 * @property string[]|null $dates  bucket start, one per measurement index
 * @property array<int, array{dimensions: string[], measurements: array<string, list<int|float>>}>|null $data
 */
class MetricAggregate extends SharedMetricAggregate
{

}
