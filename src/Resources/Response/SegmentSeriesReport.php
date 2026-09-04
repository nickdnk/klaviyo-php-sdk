<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\SegmentSeriesReport as SharedSegmentSeriesReport;

/**
 * One row per segment. `groupings` carries the `segment_id` and `statistics` maps each
 * requested statistic to a list of per-bucket values, parallel to `date_times`.
 *
 * @property array<int, array{groupings: array<string, string>, statistics: array<string, list<int|float>>}>|null $results
 * @property string[]|null $date_times  bucket start, one per statistic index
 */
class SegmentSeriesReport extends SharedSegmentSeriesReport
{

}
