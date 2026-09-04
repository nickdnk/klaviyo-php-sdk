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
 * Klaviyo's analytics reports: the numbers behind the campaign, flow, form and segment
 * dashboards. Each report is a POST whose body carries the statistics, timeframe and
 * groupings, and each family comes in two shapes — `values` aggregates the whole timeframe
 * into one number per statistic, `series` splits it into `interval` buckets.
 *
 * The response resource carries no id, so these hit their own paths rather than a shared
 * resource path.
 *
 * Campaign and flow reports paginate through the flat `page_cursor` query parameter, taken
 * from the previous response's `links.next`.
 *
 * @link https://developers.klaviyo.com/en/reference/reporting_api_overview
 */
class ReportingService extends BaseService
{

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

        return $this->report('campaign-values-reports', $report, $query, $pageCursor, $returnRequest);

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

        return $this->report('flow-series-reports', $report, $query, $pageCursor, $returnRequest);

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

        return $this->report('flow-values-reports', $report, $query, $pageCursor, $returnRequest);

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

        return $this->report('form-series-reports', $report, $query, null, $returnRequest);

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

        return $this->report('form-values-reports', $report, $query, null, $returnRequest);

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

        return $this->report('segment-series-reports', $report, $query, null, $returnRequest);

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

        return $this->report('segment-values-reports', $report, $query, null, $returnRequest);

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
