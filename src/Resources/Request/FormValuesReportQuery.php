<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\FormValuesReport;

/**
 * POST /api/form-values-reports: one aggregate value per statistic over the whole
 * timeframe, per grouping.
 *
 * `timeframe` is either a named range — `{"key": "last_30_days"}` — or an explicit
 * `{"start": "2024-01-01T00:00:00+00:00", "end": "2024-02-01T00:00:00+00:00"}` pair.
 *
 * @property string[]                                        $statistics
 * @property array{key: string}|array{start: string, end: string} $timeframe
 * @property string[]|null                                   $group_by  form_id|form_version_id
 * @property string|null                                     $filter
 */
class FormValuesReportQuery extends FormValuesReport
{

    /**
     * @param string[]                                                   $statistics
     * @param array{key: string}|array{start: string, end: string}        $timeframe
     */
    public function __construct(array $statistics, array $timeframe)
    {

        parent::__construct();
        $this->statistics = array_values($statistics);
        $this->timeframe = $timeframe;
    }

}
