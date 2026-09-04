<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\SegmentValuesReport;

/**
 * POST /api/segment-values-reports: membership counts aggregated over the whole
 * timeframe, one row per segment.
 *
 * `timeframe` is either a named range — `{"key": "last_30_days"}` — or an explicit
 * `{"start": "2024-01-01T00:00:00+00:00", "end": "2024-02-01T00:00:00+00:00"}` pair.
 *
 * @property string[]                                        $statistics  members_added|members_removed|net_members_changed|total_members
 * @property array{key: string}|array{start: string, end: string} $timeframe
 * @property string|null                                     $filter
 */
class SegmentValuesReportQuery extends SegmentValuesReport
{

    /**
     * @param string[]                                                   $statistics members_added|members_removed|net_members_changed|total_members
     * @param array{key: string}|array{start: string, end: string}        $timeframe
     */
    public function __construct(array $statistics, array $timeframe)
    {

        parent::__construct();
        $this->statistics = array_values($statistics);
        $this->timeframe = $timeframe;
    }

}
