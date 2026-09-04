<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\MetricProperty;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * A single event property Klaviyo has seen on a metric, with the type it inferred for it.
 * The full set for a metric comes from {@see MetricService::properties()}.
 */
class MetricPropertyService extends BaseService
{

    use HasGet;
    use HasRelationships;

    /**
     * `sample_values` comes back only when asked for via {@see Query::additionalFields()}.
     *
     * @link https://developers.klaviyo.com/en/reference/get_metric_property
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): MetricProperty|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    // region Relationships

    /**
     * The metric this property belongs to.
     *
     * @link https://developers.klaviyo.com/en/reference/get_metric_for_metric_property
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metric(string $metricPropertyId, ?Query $query = null, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneTrait($metricPropertyId, 'metric', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric_id_for_metric_property
     * @return Metric|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metricId(string $metricPropertyId, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneIdTrait($metricPropertyId, 'metric', $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'metric-properties';
    }
}
