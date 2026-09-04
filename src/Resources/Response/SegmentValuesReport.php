<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\SegmentValuesReport as SharedSegmentValuesReport;

/**
 * One row per segment. `groupings` carries the `segment_id` and `statistics` maps each
 * requested statistic to its aggregate value.
 *
 * @property array<int, array{groupings: array<string, string>, statistics: array<string, int|float>}>|null $results
 */
class SegmentValuesReport extends SharedSegmentValuesReport
{

}
