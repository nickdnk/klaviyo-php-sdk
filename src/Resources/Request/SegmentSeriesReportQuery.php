<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\SegmentSeriesReport;

/**
 * POST /api/segment-series-reports: membership counts per `interval` bucket, one series
 * per segment. The response's `date_times` names the bucket each index belongs to.
 *
 * `timeframe` is either a named range — `{"key": "last_30_days"}` — or an explicit
 * `{"start": "2024-01-01T00:00:00+00:00", "end": "2024-02-01T00:00:00+00:00"}` pair.
 *
 * @property string[]                                        $statistics  members_added|members_removed|net_members_changed|total_members
 * @property array{key: string}|array{start: string, end: string} $timeframe
 * @property string                                          $interval    daily|hourly|monthly|weekly
 * @property string|null                                     $filter
 */
class SegmentSeriesReportQuery extends SegmentSeriesReport
{

    /**
     * @param string[]                                                   $statistics members_added|members_removed|net_members_changed|total_members
     * @param array{key: string}|array{start: string, end: string}        $timeframe
     * @param string                                                     $interval   daily|hourly|monthly|weekly
     */
    public function __construct(array $statistics, array $timeframe, string $interval)
    {

        parent::__construct();
        $this->statistics = array_values($statistics);
        $this->timeframe = $timeframe;
        $this->interval = $interval;
    }

}
