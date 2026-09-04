<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateCustomMetric;
use nickdnk\Klaviyo\Resources\Request\MetricAggregateQuery;
use nickdnk\Klaviyo\Resources\Request\UpdateCustomMetric;
use nickdnk\Klaviyo\Resources\Request\UpdateMappedMetric;
use nickdnk\Klaviyo\Resources\Response\CustomMetric;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\MappedMetric;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\MetricAggregate;
use nickdnk\Klaviyo\Resources\Response\MetricProperty;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format and hydration checks for the metrics, custom-metrics, mapped-metrics and
 * metric-properties services. Each test pins the path, verb, query and body Klaviyo expects
 * and the class the response hydrates to.
 */
class MetricServicesTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

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

    // region Metrics

    public function testMetricListAndGet(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_metrics.200'),
            Fixtures::response('get_metric.200'),
        ]);
        $metrics = self::client($mock)->metrics;

        $list = $metrics->list((new Query())->filter(Filter::equals('integration.name', 'Shopify'))->fields('metric', 'name'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/metrics', self::path($mock));
        self::assertSame([
            'fields' => ['metric' => 'name'],
            'filter' => 'equals(integration.name,"Shopify")',
        ], self::query($mock));
        self::assertInstanceOf(Metric::class, $list['data'][0]);
        self::assertSame('RK9XjM', $list['data'][0]->id);
        self::assertSame('SDK Smoke 0904a070', $list['data'][0]->name);
        self::assertSame('2026-09-04T13:20:53+00:00', $list['data'][0]->created);
        self::assertSame('API', $list['data'][0]->integration['name']);
        self::assertSame([], $list['data'][0]->getRelationship('flow-triggers')->data, 'no flow is triggered by this metric');

        $metric = $metrics->get('m1', (new Query())->include('flow-triggers'));
        self::assertSame('/api/metrics/m1', self::path($mock));
        self::assertSame(['include' => 'flow-triggers'], self::query($mock));
        self::assertInstanceOf(Metric::class, $metric);
        self::assertSame('SkDfHe', $metric->id);
        self::assertSame('SDK Smoke', $metric->name);
        self::assertSame(
            ['object' => 'integration', 'id' => '7FtS4J', 'key' => 'api', 'name' => 'API', 'category' => 'API'],
            $metric->integration,
            'integration is an object, not just {name,category}',
        );

    }

    public function testMetricRelationships(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'flow', 'id' => 'f1', 'attributes' => ['name' => 'Post purchase', 'trigger_type' => 'Metric']]]),
            self::json([['type' => 'flow', 'id' => 'f1']]),
            Fixtures::response('get_properties_for_metric.200'),
            Fixtures::response('get_property_ids_for_metric.200'),
        ]);
        $metrics = self::client($mock)->metrics;

        $flows = $metrics->flowTriggers('m1', (new Query())->fields('flow', 'name'));
        self::assertSame('/api/metrics/m1/flow-triggers', self::path($mock));
        self::assertSame(['fields' => ['flow' => 'name']], self::query($mock));
        self::assertInstanceOf(Flow::class, $flows['data'][0]);
        self::assertSame('Post purchase', $flows['data'][0]->name);

        self::assertSame('f1', $metrics->flowTriggerIds('m1')['data'][0]->id);
        self::assertSame('/api/metrics/m1/relationships/flow-triggers', self::path($mock));

        $properties = $metrics->properties('m1', (new Query())->additionalFields('metric-property', 'sample_values'));
        self::assertSame('/api/metrics/m1/metric-properties', self::path($mock));
        self::assertSame(['additional-fields' => ['metric-property' => 'sample_values']], self::query($mock));
        self::assertCount(4, $properties['data']);
        self::assertInstanceOf(MetricProperty::class, $properties['data'][0]);
        self::assertSame('U2tEZkhlLiR2YWx1ZQ', $properties['data'][0]->id);
        self::assertSame('SDK Smoke Value', $properties['data'][0]->label);
        self::assertSame('$value', $properties['data'][0]->property);
        self::assertSame('numeric', $properties['data'][0]->inferred_type);
        self::assertNull($properties['data'][0]->sample_values, 'the collection endpoint returns sample_values: null');
        self::assertSame('SkDfHe', $properties['data'][0]->getRelationship('metric')->data->id);

        self::assertSame('U2tEZkhlLiR2YWx1ZQ', $metrics->propertyIds('m1')['data'][0]->id);
        self::assertSame('/api/metrics/m1/relationships/metric-properties', self::path($mock));

    }

    public function testMetricAggregates(): void
    {

        $mock = new MockHandler([Fixtures::response('query_metric_aggregates.200')]);

        $aggregateQuery = new MetricAggregateQuery('m1', ['count', 'sum_value']);
        $aggregateQuery->interval = 'day';
        $aggregateQuery->filter = ['greater-or-equal(datetime,2024-01-01T00:00:00)', 'less-than(datetime,2024-01-03T00:00:00)'];
        $aggregateQuery->by = ['$flow_channel'];
        $aggregateQuery->return_fields = ['count'];
        $aggregateQuery->timezone = 'Europe/Amsterdam';
        $aggregateQuery->sort = '-count';
        $aggregateQuery->page_size = 100;
        $aggregateQuery->page_cursor = 'cur';

        $aggregate = self::client($mock)->metrics->aggregates($aggregateQuery, (new Query())->fields('metric-aggregate', 'data'));

        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/metric-aggregates', self::path($mock));
        self::assertSame(['fields' => ['metric-aggregate' => 'data']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'metric-aggregate',
                'attributes' => [
                    'metric_id'     => 'm1',
                    'measurements'  => ['count', 'sum_value'],
                    'interval'      => 'day',
                    'filter'        => ['greater-or-equal(datetime,2024-01-01T00:00:00)', 'less-than(datetime,2024-01-03T00:00:00)'],
                    'by'            => ['$flow_channel'],
                    'return_fields' => ['count'],
                    'timezone'      => 'Europe/Amsterdam',
                    'sort'          => '-count',
                    'page_size'     => 100,
                    'page_cursor'   => 'cur',
                ],
            ],
        ], self::body($mock));

        self::assertInstanceOf(MetricAggregate::class, $aggregate);
        self::assertSame('664825546195297352', $aggregate->id, 'Klaviyo answers with a numeric aggregate id');
        self::assertCount(5, $aggregate->dates);
        self::assertSame('2026-08-31T22:00:00+00:00', $aggregate->dates[0]);
        self::assertCount(1, $aggregate->data);
        self::assertSame([], $aggregate->data[0]['dimensions']);
        self::assertSame([0, 0, 0, 0, 0], $aggregate->data[0]['measurements']['count']);
        self::assertSame(['count', 'sum_value', 'unique'], array_keys($aggregate->data[0]['measurements']));

    }

    // endregion

    // region Custom metrics

    public function testCustomMetricListAndGet(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_custom_metrics.200'),
            Fixtures::response('get_custom_metric.200'),
        ]);
        $customMetrics = self::client($mock)->customMetrics;

        $list = $customMetrics->list((new Query())->include('metrics'));
        self::assertSame('/api/custom-metrics', self::path($mock));
        self::assertSame(['include' => 'metrics'], self::query($mock));
        self::assertInstanceOf(CustomMetric::class, $list['data'][0]);
        self::assertSame('01M1PA9Z9DFGK85H67PJSZZ67V', $list['data'][0]->id);
        self::assertSame('sdk-smoke-09042132-custom', $list['data'][0]->name);
        // include=metrics: the source metric arrives in `included` and is spliced into the relationship.
        $source = $list['data'][0]->getRelationship('metrics');
        self::assertSame(['SkDfHe'], $source->ids());
        self::assertInstanceOf(Metric::class, $source->data[0]);
        self::assertSame('SDK Smoke', $source->data[0]->name);

        $metric = $customMetrics->get('cm1', (new Query())->fields('custom-metric', 'name'));
        self::assertSame('/api/custom-metrics/cm1', self::path($mock));
        self::assertSame(['fields' => ['custom-metric' => 'name']], self::query($mock));
        self::assertInstanceOf(CustomMetric::class, $metric);
        self::assertSame('2026-09-04T13:39:07.181640+00:00', $metric->updated, 'microsecond precision, unlike metric.updated');
        self::assertSame('value', $metric->definition['aggregation_method']);
        self::assertSame('SkDfHe', $metric->definition['metric_groups'][0]['metric_id']);

    }

    public function testCustomMetricCreateUpdateAndDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('create_custom_metric.201'),
            Fixtures::response('update_custom_metric.200'),
            new Response(204),
        ]);
        $customMetrics = self::client($mock)->customMetrics;

        $created = $customMetrics->create(new CreateCustomMetric(
            'Net revenue',
            'value',
            [
                ['metric_id' => 'm1', 'value_property' => '$value'],
                ['metric_id' => 'm2', 'metric_filters' => [['field' => 'Currency', 'operator' => 'equals', 'value' => 'EUR']]],
            ],
        ));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/custom-metrics', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'custom-metric',
                'attributes'    => [
                    'name'       => 'Net revenue',
                    'definition' => [
                        'aggregation_method' => 'value',
                        'metric_groups'      => [
                            ['metric_id' => 'm1', 'value_property' => '$value'],
                            ['metric_id' => 'm2', 'metric_filters' => [['field' => 'Currency', 'operator' => 'equals', 'value' => 'EUR']]],
                        ],
                    ],
                ],
            ],
        ], self::body($mock), 'no relationships member: the spec derives linked metrics from the definition');
        self::assertInstanceOf(CustomMetric::class, $created);
        self::assertSame('01M1PA9Z9DFGK85H67PJSZZ67V', $created->id, 'custom metrics get ULIDs, not the usual short ids');
        self::assertSame(['SkDfHe'], $created->getRelationship('metrics')->ids(), 'derived from the definition');

        $update = new UpdateCustomMetric('cm1');
        $update->name = 'Gross revenue';
        $update->definition = ['aggregation_method' => 'count', 'metric_groups' => [['metric_id' => 'm1']]];
        $updated = $customMetrics->update($update);
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/custom-metrics/cm1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'custom-metric',
                'attributes' => [
                    'name'       => 'Gross revenue',
                    'definition' => ['aggregation_method' => 'count', 'metric_groups' => [['metric_id' => 'm1']]],
                ],
                'id'         => 'cm1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(CustomMetric::class, $updated);
        self::assertSame('sdk-smoke-09042132-custom-renamed', $updated->name);
        self::assertSame('count', $updated->definition['aggregation_method']);
        self::assertNull($updated->definition['metric_groups'][0]['value_property'], 'value_property is nulled by a count aggregation');

        self::assertNull($customMetrics->delete('cm1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/custom-metrics/cm1', self::path($mock));

    }

    public function testCustomMetricSourceMetrics(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_metrics_for_custom_metric.200'),
            Fixtures::response('get_metric_ids_for_custom_metric.200'),
        ]);
        $customMetrics = self::client($mock)->customMetrics;

        $metrics = $customMetrics->metrics('cm1', (new Query())->fields('metric', 'name'));
        self::assertSame('/api/custom-metrics/cm1/metrics', self::path($mock));
        self::assertSame(['fields' => ['metric' => 'name']], self::query($mock));
        self::assertInstanceOf(Metric::class, $metrics['data'][0]);
        self::assertSame('SkDfHe', $metrics['data'][0]->id);
        self::assertSame('SDK Smoke', $metrics['data'][0]->name);

        self::assertSame('SkDfHe', $customMetrics->metricIds('cm1')['data'][0]->id);
        self::assertSame('/api/custom-metrics/cm1/relationships/metrics', self::path($mock));

    }

    // endregion

    // region Mapped metrics

    public function testMappedMetricListGetAndUpdate(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_mapped_metrics.200'),
            Fixtures::response('get_mapped_metric.200'),
            // no update_mapped_metric.200 was recorded (the live account only produced 403/429).
            self::json(['type' => 'mapped-metric', 'id' => 'revenue', 'attributes' => ['updated' => '2024-03-03T00:00:00+00:00']]),
        ]);
        $mappedMetrics = self::client($mock)->mappedMetrics;

        $list = $mappedMetrics->list((new Query())->include('metric', 'custom-metric'));
        self::assertSame('/api/mapped-metrics', self::path($mock));
        self::assertSame(['include' => 'metric,custom-metric'], self::query($mock));
        self::assertInstanceOf(MappedMetric::class, $list['data'][0]);
        self::assertSame('revenue', $list['data'][0]->id, 'mapped metrics are keyed by their slug');
        self::assertSame('2026-09-04T13:04:58.039222+00:00', $list['data'][0]->updated);
        self::assertSame(
            ['revenue', 'viewed_product', 'added_to_cart', 'cancelled_sales', 'ordered_product', 'refunded_sales', 'started_checkout'],
            array_map(fn(MappedMetric $m) => $m->id, $list['data']),
        );
        self::assertNull($list['data'][0]->getRelationship('metric'), 'revenue is unmapped: no relationship at all');
        // viewed_product is mapped, and include=metric splices the metric in.
        self::assertSame('Viewed Product', $list['data'][1]->getRelationship('metric')->data->name);

        $mapped = $mappedMetrics->get('revenue', (new Query())->fields('mapped-metric', 'updated'));
        self::assertSame('/api/mapped-metrics/revenue', self::path($mock));
        self::assertSame(['fields' => ['mapped-metric' => 'updated']], self::query($mock));
        self::assertInstanceOf(MappedMetric::class, $mapped);
        self::assertSame('2026-09-04T13:04:58.039222+00:00', $mapped->updated);

        $updated = $mappedMetrics->update(new UpdateMappedMetric('revenue', metricId: 'm1', customMetricId: 'cm1'));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/mapped-metrics/revenue', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'mapped-metric',
                'relationships' => [
                    'metric'        => ['data' => ['type' => 'metric', 'id' => 'm1']],
                    'custom-metric' => ['data' => ['type' => 'custom-metric', 'id' => 'cm1']],
                ],
                'id'            => 'revenue',
            ],
        ], self::body($mock));
        self::assertInstanceOf(MappedMetric::class, $updated);
        self::assertSame('2024-03-03T00:00:00+00:00', $updated->updated);

    }

    public function testMappedMetricToOneRelationships(): void
    {

        $mock = new MockHandler([
            self::json(['type' => 'metric', 'id' => 'm1', 'attributes' => ['name' => 'Placed Order']]),
            self::json(['type' => 'metric', 'id' => 'm1']),
            self::json(['type' => 'custom-metric', 'id' => 'cm1', 'attributes' => ['name' => 'Net revenue']]),
            self::json(['type' => 'custom-metric', 'id' => 'cm1']),
        ]);
        $mappedMetrics = self::client($mock)->mappedMetrics;

        $metric = $mappedMetrics->metric('revenue', (new Query())->fields('metric', 'name'));
        self::assertSame('/api/mapped-metrics/revenue/metric', self::path($mock));
        self::assertSame(['fields' => ['metric' => 'name']], self::query($mock));
        self::assertInstanceOf(Metric::class, $metric);
        self::assertSame('Placed Order', $metric->name);

        self::assertSame('m1', $mappedMetrics->metricId('revenue')->id);
        self::assertSame('/api/mapped-metrics/revenue/relationships/metric', self::path($mock));

        $customMetric = $mappedMetrics->customMetric('revenue');
        self::assertSame('/api/mapped-metrics/revenue/custom-metric', self::path($mock));
        self::assertInstanceOf(CustomMetric::class, $customMetric);
        self::assertSame('Net revenue', $customMetric->name);

        self::assertSame('cm1', $mappedMetrics->customMetricId('revenue')->id);
        self::assertSame('/api/mapped-metrics/revenue/relationships/custom-metric', self::path($mock));

    }

    // endregion

    // region Metric properties

    public function testMetricPropertyGetAndMetric(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_metric_property.200'),
            Fixtures::response('get_metric_for_metric_property.200'),
            Fixtures::response('get_metric_id_for_metric_property.200'),
        ]);
        $metricProperties = self::client($mock)->metricProperties;

        $property = $metricProperties->get('mp1', (new Query())->additionalFields('metric-property', 'sample_values')->include('metric'));
        self::assertSame('/api/metric-properties/mp1', self::path($mock));
        self::assertSame([
            'additional-fields' => ['metric-property' => 'sample_values'],
            'include'           => 'metric',
        ], self::query($mock));
        self::assertInstanceOf(MetricProperty::class, $property);
        self::assertSame('SDK Smoke Value', $property->label);
        self::assertSame('$value', $property->property);
        self::assertSame('numeric', $property->inferred_type);
        self::assertSame([], $property->sample_values, 'no events carried the property yet');
        // include=metric: the owning metric is spliced into the relationship.
        self::assertSame('SDK Smoke', $property->getRelationship('metric')->data->name);

        $metric = $metricProperties->metric('mp1', (new Query())->fields('metric', 'name'));
        self::assertSame('/api/metric-properties/mp1/metric', self::path($mock));
        self::assertSame(['fields' => ['metric' => 'name']], self::query($mock));
        self::assertInstanceOf(Metric::class, $metric);
        self::assertSame('SkDfHe', $metric->id);
        self::assertSame('SDK Smoke', $metric->name);

        self::assertSame('SkDfHe', $metricProperties->metricId('mp1')->id);
        self::assertSame('/api/metric-properties/mp1/relationships/metric', self::path($mock));

    }

    public function testMetricPropertyGetReturnsNullOn404(): void
    {

        $mock = new MockHandler([Fixtures::response('get_metric_property.404')]);

        self::assertNull(self::client($mock)->metricProperties->get('missing'));

    }

    // endregion

}
