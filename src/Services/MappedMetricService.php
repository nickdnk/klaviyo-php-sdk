<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateMappedMetric;
use nickdnk\Klaviyo\Resources\Response\CustomMetric;
use nickdnk\Klaviyo\Resources\Response\MappedMetric;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Klaviyo's fixed mapping slots — `added_to_cart`, `cancelled_sales`, `ordered_product`,
 * `refunded_sales`, `revenue`, `started_checkout` and `viewed_product` — each pointing at the
 * metric or custom metric the account uses for that concept.
 *
 * - The slot name is the resource id, so the set is fixed and only the target ever changes.
 * - A slot accepts two updates per day; further ones answer 403.
 *
 * @link https://developers.klaviyo.com/en/reference/metrics_api_overview
 */
class MappedMetricService extends BaseService
{

    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_mapped_metrics
     * @return array{data: MappedMetric[], links: ?PaginationLinks}|RequestInterface
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
     * Every MappedMetric matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, MappedMetric>
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
     * @link https://developers.klaviyo.com/en/reference/get_mapped_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): MappedMetric|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_mapped_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateMappedMetric $mappedMetric, ?Query $query = null, bool $returnRequest = false): MappedMetric|RequestInterface
    {

        return $this->updateTrait($mappedMetric, $query, $returnRequest);

    }

    // region Relationships

    /**
     * Null when the slot points at a custom metric instead.
     *
     * @link https://developers.klaviyo.com/en/reference/get_metric_for_mapped_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metric(string $mappedMetricId, ?Query $query = null, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneTrait($mappedMetricId, 'metric', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric_id_for_mapped_metric
     * @return Metric|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metricId(string $mappedMetricId, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneIdTrait($mappedMetricId, 'metric', $returnRequest);

    }

    /**
     * Null when the slot points at a plain metric instead.
     *
     * @link https://developers.klaviyo.com/en/reference/get_custom_metric_for_mapped_metric
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function customMetric(string $mappedMetricId, ?Query $query = null, bool $returnRequest = false): CustomMetric|RequestInterface|null
    {

        return $this->relatedOneTrait($mappedMetricId, 'custom-metric', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_custom_metric_id_for_mapped_metric
     * @return CustomMetric|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function customMetricId(string $mappedMetricId, bool $returnRequest = false): CustomMetric|RequestInterface|null
    {

        return $this->relatedOneIdTrait($mappedMetricId, 'custom-metric', $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'mapped-metrics';
    }
}
