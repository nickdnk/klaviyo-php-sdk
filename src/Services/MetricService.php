<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\MetricAggregateQuery;
use nickdnk\Klaviyo\Resources\Response\Flow;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\MetricAggregate;
use nickdnk\Klaviyo\Resources\Response\MetricProperty;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * Metrics are the event types an account has seen. They are created by integrations and by
 * {@see EventService::create()}, never here.
 *
 * - Read-only and permanent: a metric cannot be renamed or removed.
 * - {@see self::aggregates()} is a POST that rolls a metric's events into buckets. It rejects
 *   `sort`, wants a `page_size` of at least 500, lags {@see EventService::list()} by a few
 *   seconds and does not accept custom metrics.
 *
 * @link https://developers.klaviyo.com/en/reference/metrics_api_overview
 */
class MetricService extends BaseService
{

    use HasGet;
    use HasList;
    use HasRelationships;

    private const string PATH_AGGREGATES = 'metric-aggregates';

    /**
     * Filterable by integration `name` and `category`; the page size is fixed by Klaviyo.
     *
     * @link https://developers.klaviyo.com/en/reference/get_metrics
     * @return array{data: Metric[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->listTrait($query, $next, $returnRequest);

    }

    /**
     * Every Metric matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, Metric>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function iterate(?Query $query = null): Generator
    {
        return (new Paginator(fn(?string $next) => $this->list($query, $next)))->items();
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * Aggregates the events behind one metric into buckets. Klaviyo needs at least one
     * `datetime` clause in the query's `filter` to bound the range.
     *
     * @link https://developers.klaviyo.com/en/reference/query_metric_aggregates
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function aggregates(MetricAggregateQuery $aggregateQuery, ?Query $query = null, bool $returnRequest = false): MetricAggregate|RequestInterface
    {

        $result = $this->request(
                           'POST',
                           self::PATH_AGGREGATES,
                           $aggregateQuery,
            query:         $query?->toArray() ?: null,
            returnRequest: $returnRequest,
        );

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_flows_triggered_by_metric
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggers(string $metricId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($metricId, 'flow-triggers', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_ids_for_flows_triggered_by_metric
     * @return array{data: Flow[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function flowTriggerIds(string $metricId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($metricId, 'flow-triggers', returnRequest: $returnRequest);

    }

    /**
     * `sample_values` comes back only when asked for with `additionalFields('metric-property', 'sample_values')`.
     *
     * @link https://developers.klaviyo.com/en/reference/get_properties_for_metric
     * @return array{data: MetricProperty[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function properties(string $metricId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($metricId, 'metric-properties', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_property_ids_for_metric
     * @return array{data: MetricProperty[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function propertyIds(string $metricId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($metricId, 'metric-properties', returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'metrics';
    }
}
