<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEventsJob;
use nickdnk\Klaviyo\Resources\Request\CreateEventForProfile;
use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Response\Metric;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

class EventService extends BaseService
{

    use HasCreate;
    use HasGet;
    use HasList;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_events
     * @return array{data: Event[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_event
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Event|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_event
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateEventForProfile $event, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->createTrait($event, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_create_events
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreate(BulkCreateEventsJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', 'event-bulk-create-jobs', $job, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_for_event
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profile(string $eventId, ?Query $query = null, bool $returnRequest = false): Profile|RequestInterface|null
    {

        return $this->relatedOneTrait($eventId, 'profile', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_id_for_event
     * @return Profile|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileId(string $eventId, bool $returnRequest = false): Profile|RequestInterface|null
    {

        return $this->relatedOneIdTrait($eventId, 'profile', $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric_for_event
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metric(string $eventId, ?Query $query = null, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneTrait($eventId, 'metric', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_metric_id_for_event
     * @return Metric|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function metricId(string $eventId, bool $returnRequest = false): Metric|RequestInterface|null
    {

        return $this->relatedOneIdTrait($eventId, 'metric', $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'events';
    }
}
