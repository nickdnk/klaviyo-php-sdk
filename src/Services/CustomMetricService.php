<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateCustomMetric;
use nickdnk\Klaviyo\Resources\Request\UpdateCustomMetric;
use nickdnk\Klaviyo\Resources\Response\CustomMetric;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Custom metrics are account-defined aggregations over one or more source metrics, used by
 * reports and by the mapped metric slots on {@see MappedMetricService}.
 *
 * They are not supported by {@see MetricService::aggregates()}, which only queries real metrics.
 *
 * @link https://developers.klaviyo.com/en/reference/custom_metrics_api_overview
 */
class CustomMetricService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_custom_metrics
     * @return array{data: CustomMetric[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_custom_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CustomMetric|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_custom_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCustomMetric $metric, ?Query $query = null, bool $returnRequest = false): CustomMetric|RequestInterface
    {

        return $this->createTrait($metric, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_custom_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCustomMetric $metric, ?Query $query = null, bool $returnRequest = false): CustomMetric|RequestInterface
    {

        return $this->updateTrait($metric, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metrics_for_custom_metric
     * @return array{data: Metric[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metrics(string $customMetricId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($customMetricId, 'metrics', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric_ids_for_custom_metric
     * @return array{data: Metric[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metricIds(string $customMetricId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($customMetricId, 'metrics', returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'custom-metrics';
    }
}
