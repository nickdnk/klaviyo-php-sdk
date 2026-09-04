<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\FlowSeriesReport as SharedFlowSeriesReport;

/**
 * One row per grouping combination. `groupings` echoes the applied `group_by` values and
 * `statistics` maps each requested statistic to a list of per-bucket values, parallel to
 * `date_times`.
 *
 * @property array<int, array{groupings: array<string, string>, statistics: array<string, list<int|float>>}>|null $results
 * @property string[]|null $date_times  bucket start, one per statistic index
 */
class FlowSeriesReport extends SharedFlowSeriesReport
{

}
