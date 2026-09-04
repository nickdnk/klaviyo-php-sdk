<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\GuzzleTransport;
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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use nickdnk\Klaviyo\Services\ReportingService;

/**
 * Wire-format and hydration checks for the seven reporting endpoints. Each test pins the
 * POST path, the full request body, the query parameters (sparse fieldsets plus the flat
 * `page_cursor` the campaign and flow reports paginate with) and the class the response
 * hydrates to.
 */
class ReportingServiceTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withTransport(GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)), fn() => new APIClient('tkn'));

    }

    private static function path(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getUri()->getPath();

    }

    private static function query(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function body(MockHandler $mock): array
    {

        return json_decode((string)$mock->getLastRequest()->getBody(), true);

    }

    // region Campaigns

    public function testCampaignValues(): void
    {

        $mock = new MockHandler([Fixtures::response('query_campaign_values.200')]);

        $report = new CampaignValuesReportQuery(['opens', 'open_rate'], ['key' => 'last_30_days'], 'cm1');
        $report->group_by = ['campaign_id', 'send_channel'];
        $report->filter = 'equals(campaign_id,"c1")';

        $result = self::client($mock)->reports->campaignValues(
            $report,
            (new Query())->fields('campaign-values-report', 'results'),
            'cursor-1',
        );

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/campaign-values-reports', self::path($mock));
        self::assertSame([
            'fields'      => ['campaign-values-report' => 'results'],
            'page_cursor' => 'cursor-1',
        ], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'campaign-values-report',
                'attributes' => [
                    'statistics'           => ['opens', 'open_rate'],
                    'timeframe'            => ['key' => 'last_30_days'],
                    'conversion_metric_id' => 'cm1',
                    'group_by'             => ['campaign_id', 'send_channel'],
                    'filter'               => 'equals(campaign_id,"c1")',
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(CampaignValuesReport::class, $result);
        self::assertSame('d1a4b623-a90d-491f-89ce-56cca8464b27', $result->id, 'reports come back with a UUID id');
        self::assertSame([], $result->results, 'the recording account sent no campaigns in the timeframe');

    }

    // endregion

    // region Flows

    public function testFlowSeries(): void
    {

        $mock = new MockHandler([Fixtures::response('query_flow_series.200')]);

        $report = new FlowSeriesReportQuery(
            ['opens'],
            ['start' => '2024-01-05T00:00:00+00:00', 'end' => '2024-01-08T00:00:00+00:00'],
            'daily',
            'cm1',
        );
        $report->group_by = ['flow_id'];

        $result = self::client($mock)->reports->flowSeries($report, pageCursor: 'cursor-2');

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flow-series-reports', self::path($mock));
        self::assertSame(['page_cursor' => 'cursor-2'], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'flow-series-report',
                'attributes' => [
                    'statistics'           => ['opens'],
                    'timeframe'            => ['start' => '2024-01-05T00:00:00+00:00', 'end' => '2024-01-08T00:00:00+00:00'],
                    'interval'             => 'daily',
                    'conversion_metric_id' => 'cm1',
                    'group_by'             => ['flow_id'],
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(FlowSeriesReport::class, $result);
        self::assertSame('a31196c7-0579-47dd-9bd1-b1715ce174ec', $result->id);
        self::assertSame([], $result->results, 'no flow sent anything in the recorded timeframe');
        // date_times is filled in regardless: one bucket per day of the requested interval.
        self::assertCount(8, $result->date_times);
        self::assertSame('2026-08-28T00:00:00+00:00', $result->date_times[0]);
        self::assertSame('2026-09-04T00:00:00+00:00', $result->date_times[7]);

    }

    public function testFlowValues(): void
    {

        $mock = new MockHandler([Fixtures::response('query_flow_values.200')]);

        $report = new FlowValuesReportQuery(['conversion_value'], ['key' => 'last_7_days'], 'cm1');

        $result = self::client($mock)->reports->flowValues($report);

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/flow-values-reports', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'flow-values-report',
                'attributes' => [
                    'statistics'           => ['conversion_value'],
                    'timeframe'            => ['key' => 'last_7_days'],
                    'conversion_metric_id' => 'cm1',
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(FlowValuesReport::class, $result);
        self::assertSame('a738b3ea-1c8e-4b74-b254-c39697aae326', $result->id);
        self::assertSame([], $result->results);

    }

    // endregion

    // region Forms

    public function testFormSeries(): void
    {

        $mock = new MockHandler([Fixtures::response('query_form_series.200')]);

        $report = new FormSeriesReportQuery(['submits', 'submit_rate'], ['key' => 'last_week'], 'weekly');
        $report->group_by = ['form_id', 'form_version_id'];
        $report->filter = 'equals(form_id,"abc123")';

        $result = self::client($mock)->reports->formSeries($report, (new Query())->fields('form-series-report', 'results'));

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/form-series-reports', self::path($mock));
        self::assertSame(['fields' => ['form-series-report' => 'results']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'form-series-report',
                'attributes' => [
                    'statistics' => ['submits', 'submit_rate'],
                    'timeframe'  => ['key' => 'last_week'],
                    'interval'   => 'weekly',
                    'group_by'   => ['form_id', 'form_version_id'],
                    'filter'     => 'equals(form_id,"abc123")',
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(FormSeriesReport::class, $result);
        self::assertSame('702b69f8-3b4c-47cc-8857-437695caa579', $result->id);
        self::assertSame([], $result->results, 'no form was viewed in the recorded timeframe');
        self::assertSame([
            '2026-08-03T00:00:00+00:00',
            '2026-08-10T00:00:00+00:00',
            '2026-08-17T00:00:00+00:00',
            '2026-08-24T00:00:00+00:00',
            '2026-08-31T00:00:00+00:00',
        ], $result->date_times, 'interval=weekly buckets by Monday');

    }

    public function testFormValues(): void
    {

        $mock = new MockHandler([Fixtures::response('query_form_values.200')]);

        $report = new FormValuesReportQuery(['viewed_form', 'submit_rate'], ['key' => 'last_month']);

        $result = self::client($mock)->reports->formValues($report);

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/form-values-reports', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'form-values-report',
                'attributes' => [
                    'statistics' => ['viewed_form', 'submit_rate'],
                    'timeframe'  => ['key' => 'last_month'],
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(FormValuesReport::class, $result);
        self::assertSame('c06d9585-2f9c-475a-a620-572f6780d2e6', $result->id);
        self::assertSame([], $result->results);

    }

    // endregion

    // region Segments

    public function testSegmentSeries(): void
    {

        $mock = new MockHandler([Fixtures::response('query_segment_series.200')]);

        $report = new SegmentSeriesReportQuery(['total_members'], ['key' => 'last_90_days'], 'daily');
        $report->filter = 'equals(segment_id,"s1")';

        $result = self::client($mock)->reports->segmentSeries($report);

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/segment-series-reports', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'segment-series-report',
                'attributes' => [
                    'statistics' => ['total_members'],
                    'timeframe'  => ['key' => 'last_90_days'],
                    'interval'   => 'daily',
                    'filter'     => 'equals(segment_id,"s1")',
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(SegmentSeriesReport::class, $result);
        self::assertSame('ad04a739-ed2d-4b33-9d5f-b7cf4f1c962c', $result->id);
        self::assertCount(4, $result->results, 'one row per segment of the recording account');
        self::assertSame(['segment_id' => 'RvKscW'], $result->results[0]['groupings']);
        self::assertSame(
            ['net_members_changed', 'total_members'],
            array_keys($result->results[0]['statistics']),
            'both statistics come back even though only total_members was asked for',
        );
        self::assertCount(31, $result->results[0]['statistics']['total_members'], 'one value per date_times bucket');
        self::assertCount(31, $result->date_times);
        self::assertSame('2026-08-05T00:00:00+00:00', $result->date_times[0]);

    }

    public function testSegmentValues(): void
    {

        $mock = new MockHandler([Fixtures::response('query_segment_values.200')]);

        $report = new SegmentValuesReportQuery(['total_members', 'net_members_changed'], ['key' => 'this_month']);

        $result = self::client($mock)->reports->segmentValues($report, (new Query())->fields('segment-values-report', 'results'));

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/segment-values-reports', self::path($mock));
        self::assertSame(['fields' => ['segment-values-report' => 'results']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'segment-values-report',
                'attributes' => [
                    'statistics' => ['total_members', 'net_members_changed'],
                    'timeframe'  => ['key' => 'this_month'],
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(SegmentValuesReport::class, $result);
        self::assertSame('122d75bf-01ac-4577-b8b1-6c73e9c152a7', $result->id);
        self::assertSame(
            ['RvKscW', 'W8qhB7', 'SAx2Kq', 'SnhZTm'],
            array_column(array_column($result->results, 'groupings'), 'segment_id'),
        );
        self::assertSame(
            ['members_added' => 0, 'total_members' => 0],
            $result->results[0]['statistics'],
            'the account answered members_added, not the requested net_members_changed',
        );

    }

    // endregion

    public function testReportingServiceHasNoBasePath(): void
    {

        $m = new ReflectionMethod(ReportingService::class, 'apiPath');
        self::assertSame('', $m->invoke(self::client(new MockHandler())->reports), 'reports have no collection path; each report is its own POST endpoint');

    }

    // endregion

    // region APIClient

}
