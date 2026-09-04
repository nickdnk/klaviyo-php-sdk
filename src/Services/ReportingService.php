<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CampaignValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FlowSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FlowValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FormSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\FormValuesReportQuery;
use nickdnk\Klaviyo\Resources\Request\SegmentSeriesReportQuery;
use nickdnk\Klaviyo\Resources\Request\SegmentValuesReportQuery;
use nickdnk\Klaviyo\Resources\Response\CampaignValuesReport;
use nickdnk\Klaviyo\Resources\Response\FlowSeriesReport;
use nickdnk\Klaviyo\Resources\Response\FlowValuesReport;
use nickdnk\Klaviyo\Resources\Response\FormSeriesReport;
use nickdnk\Klaviyo\Resources\Response\FormValuesReport;
use nickdnk\Klaviyo\Resources\Response\SegmentSeriesReport;
use nickdnk\Klaviyo\Resources\Response\SegmentValuesReport;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;
use Psr\Http\Message\RequestInterface;

/**
 * The numbers behind Klaviyo's campaign, flow, form and segment dashboards. Every report is a
 * POST whose body carries the statistics, timeframe and groupings.
 *
 * - Each family comes in two shapes: `values` aggregates the whole timeframe into one number per
 *   statistic, `series` splits it into `interval` buckets.
 * - Campaign and flow reports paginate through a flat `page_cursor` parameter taken from the
 *   previous response's `links.next`.
 * - Flow reports need `flow_message_id` alongside `flow_id` in `group_by`.
 * - The response resources carry no id, so these methods hit their own paths rather than a
 *   shared resource path.
 *
 * @link https://developers.klaviyo.com/en/reference/reporting_api_overview
 */
class ReportingService extends BaseService
{

    private const string PATH_CAMPAIGN_VALUES = 'campaign-values-reports';
    private const string PATH_FLOW_SERIES     = 'flow-series-reports';
    private const string PATH_FLOW_VALUES     = 'flow-values-reports';
    private const string PATH_FORM_SERIES     = 'form-series-reports';
    private const string PATH_FORM_VALUES     = 'form-values-reports';
    private const string PATH_SEGMENT_SERIES  = 'segment-series-reports';
    private const string PATH_SEGMENT_VALUES  = 'segment-values-reports';

    /**
     * @link https://developers.klaviyo.com/en/reference/query_campaign_values
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function campaignValues(CampaignValuesReportQuery $report, ?Query $query = null, ?string $pageCursor = null,
        bool $returnRequest = false
    ): CampaignValuesReport|RequestInterface
    {

        return $this->report(self::PATH_CAMPAIGN_VALUES, $report, $query, $pageCursor, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_flow_series
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowSeries(FlowSeriesReportQuery $report, ?Query $query = null, ?string $pageCursor = null,
        bool $returnRequest = false
    ): FlowSeriesReport|RequestInterface
    {

        return $this->report(self::PATH_FLOW_SERIES, $report, $query, $pageCursor, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_flow_values
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowValues(FlowValuesReportQuery $report, ?Query $query = null, ?string $pageCursor = null,
        bool $returnRequest = false
    ): FlowValuesReport|RequestInterface
    {

        return $this->report(self::PATH_FLOW_VALUES, $report, $query, $pageCursor, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_form_series
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function formSeries(FormSeriesReportQuery $report, ?Query $query = null, bool $returnRequest = false): FormSeriesReport|RequestInterface
    {

        return $this->report(self::PATH_FORM_SERIES, $report, $query, null, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_form_values
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function formValues(FormValuesReportQuery $report, ?Query $query = null, bool $returnRequest = false): FormValuesReport|RequestInterface
    {

        return $this->report(self::PATH_FORM_VALUES, $report, $query, null, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_segment_series
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function segmentSeries(SegmentSeriesReportQuery $report, ?Query $query = null, bool $returnRequest = false): SegmentSeriesReport|RequestInterface
    {

        return $this->report(self::PATH_SEGMENT_SERIES, $report, $query, null, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/query_segment_values
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function segmentValues(SegmentValuesReportQuery $report, ?Query $query = null, bool $returnRequest = false): SegmentValuesReport|RequestInterface
    {

        return $this->report(self::PATH_SEGMENT_VALUES, $report, $query, null, $returnRequest);

    }

    /**
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function report(string $path, TypedResource $report, ?Query $query, ?string $pageCursor,
        bool $returnRequest
    ): IdentifiableResource|RequestInterface
    {

        $params = $query?->toArray() ?: [];
        if ($pageCursor !== null && $pageCursor !== '') {
            $params['page_cursor'] = $pageCursor;
        }

        $result = $this->request(
                           'POST',
                           $path,
                           $report,
            query:         $params ?: null,
            returnRequest: $returnRequest,
        );

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    protected function apiPath(): string
    {

        return '';
    }
}
