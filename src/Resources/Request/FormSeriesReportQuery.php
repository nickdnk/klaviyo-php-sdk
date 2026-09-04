<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\FormSeriesReport;

/**
 * POST /api/form-series-reports: one value per statistic per `interval` bucket, per
 * grouping. The response's `date_times` names the bucket each index belongs to.
 *
 * `timeframe` is either a named range — `{"key": "last_30_days"}` — or an explicit
 * `{"start": "2024-01-01T00:00:00+00:00", "end": "2024-02-01T00:00:00+00:00"}` pair.
 *
 * @property string[]                                        $statistics
 * @property array{key: string}|array{start: string, end: string} $timeframe
 * @property string                                          $interval  daily|hourly|monthly|weekly
 * @property string[]|null                                   $group_by  form_id|form_version_id
 * @property string|null                                     $filter
 */
class FormSeriesReportQuery extends FormSeriesReport
{

    /**
     * @param string[]                                                   $statistics
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
