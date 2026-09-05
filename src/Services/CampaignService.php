<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CampaignClone;
use nickdnk\Klaviyo\Resources\Request\CampaignRecipientEstimationJob as RequestCampaignRecipientEstimationJob;
use nickdnk\Klaviyo\Resources\Request\CampaignSendJob as RequestCampaignSendJob;
use nickdnk\Klaviyo\Resources\Request\CancelCampaignSend;
use nickdnk\Klaviyo\Resources\Request\CreateCampaign;
use nickdnk\Klaviyo\Resources\Request\UpdateCampaign;
use nickdnk\Klaviyo\Resources\Response\Campaign;
use nickdnk\Klaviyo\Resources\Response\CampaignMessage;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimation;
use nickdnk\Klaviyo\Resources\Response\CampaignRecipientEstimationJob;
use nickdnk\Klaviyo\Resources\Response\CampaignSendJob;
use nickdnk\Klaviyo\Resources\Response\Tag;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasBulkJobs;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Campaigns and the jobs that hang off one: cloning, the send job that delivers it, and the
 * recipient estimation that sizes its audience.
 *
 * - {@see self::list()} requires a channel filter, e.g. `equals(messages.channel,"email")`, and
 *   only supports `contains` on `name`.
 * - Sending and estimating are asynchronous: both answer with a job keyed by the campaign id,
 *   polled through {@see self::getSendJob()} and {@see self::getRecipientEstimationJob()}.
 * - `custom_tracking_params` must include `utm_source` and `utm_medium`.
 *
 * @link https://developers.klaviyo.com/en/reference/campaigns_api_overview
 */
class CampaignService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_CLONE                  = 'campaign-clone';
    private const string PATH_SEND_JOBS              = 'campaign-send-jobs';
    private const string PATH_ESTIMATION_JOBS        = 'campaign-recipient-estimation-jobs';
    private const string PATH_RECIPIENT_ESTIMATIONS  = 'campaign-recipient-estimations';

    /**
     * A channel filter is required, e.g. `equals(messages.channel,"email")`.
     *
     * @link https://developers.klaviyo.com/en/reference/get_campaigns
     * @return array{data: Campaign[], links: ?PaginationLinks}|RequestInterface
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
     * Every Campaign matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, Campaign>
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
     * @link https://developers.klaviyo.com/en/reference/get_campaign
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Campaign|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_campaign
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCampaign $campaign, ?Query $query = null, bool $returnRequest = false): Campaign|RequestInterface
    {

        return $this->createTrait($campaign, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_campaign
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCampaign $campaign, ?Query $query = null, bool $returnRequest = false): Campaign|RequestInterface
    {

        return $this->updateTrait($campaign, $query, $returnRequest);

    }

    /**
     * Copies the campaign and its messages into a new draft.
     *
     * @link https://developers.klaviyo.com/en/reference/create_campaign_clone
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function clone(CampaignClone $clone, ?Query $query = null, bool $returnRequest = false): Campaign|RequestInterface
    {

        $result = $this->request('POST', self::PATH_CLONE, $clone, $query?->toArray() ?: null, $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_messages_for_campaign
     * @return array{data: CampaignMessage[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function messages(string $campaignId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($campaignId, 'campaign-messages', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_message_ids_for_campaign
     * @return array{data: CampaignMessage[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function messageIds(string $campaignId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($campaignId, 'campaign-messages', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tags_for_campaign
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tags(string $campaignId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($campaignId, 'tags', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tag_ids_for_campaign
     * @return array{data: Tag[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function tagIds(string $campaignId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($campaignId, 'tags', returnRequest: $returnRequest);

    }

    // endregion

    // region Send jobs

    /**
     * Queues the campaign for delivery. The send job is keyed by the campaign id.
     *
     * @link https://developers.klaviyo.com/en/reference/send_campaign
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function send(string $campaignId, bool $returnRequest = false): CampaignSendJob|RequestInterface|null
    {

        return $this->bulkJobSubmit(self::PATH_SEND_JOBS, new RequestCampaignSendJob($campaignId), $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_send_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getSendJob(string $id, ?Query $query = null, bool $returnRequest = false): CampaignSendJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_SEND_JOBS, $id, $query, $returnRequest);

    }

    /**
     * Cancels the send for good, leaving the campaign Cancelled; use revertSend() to edit and reschedule instead.
     *
     * @link https://developers.klaviyo.com/en/reference/cancel_campaign_send
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function cancelSend(string $id, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request(
                           'PATCH',
                           self::PATH_SEND_JOBS . '/' . $id,
                           new CancelCampaignSend($id, CancelCampaignSend::ACTION_CANCEL),
            returnRequest: $returnRequest,
        );

    }

    /**
     * Stops the send and returns the campaign to Draft. Same endpoint as cancelSend(), which is why
     * both link the same reference.
     *
     * @link https://developers.klaviyo.com/en/reference/cancel_campaign_send
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function revertSend(string $id, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request(
                           'PATCH',
                           self::PATH_SEND_JOBS . '/' . $id,
                           new CancelCampaignSend($id, CancelCampaignSend::ACTION_REVERT),
            returnRequest: $returnRequest,
        );

    }

    // endregion

    // region Recipient estimation

    /**
     * Recounts the campaign's audience. The estimation job is keyed by the campaign id; read the
     * count back with getRecipientEstimation().
     *
     * @link https://developers.klaviyo.com/en/reference/refresh_campaign_recipient_estimation
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function refreshRecipientEstimation(string $campaignId, bool $returnRequest = false): CampaignRecipientEstimationJob|RequestInterface|null
    {

        return $this->bulkJobSubmit(self::PATH_ESTIMATION_JOBS, new RequestCampaignRecipientEstimationJob($campaignId), $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_campaign_recipient_estimation_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getRecipientEstimationJob(string $id, ?Query $query = null, bool $returnRequest = false): CampaignRecipientEstimationJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_ESTIMATION_JOBS, $id, $query, $returnRequest);

    }

    /**
     * The last counted audience size, keyed by the campaign id.
     *
     * @link https://developers.klaviyo.com/en/reference/get_campaign_recipient_estimation
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getRecipientEstimation(string $campaignId, ?Query $query = null, bool $returnRequest = false): CampaignRecipientEstimation|RequestInterface|null
    {

        try {
            $result = $this->request(
                               'GET',
                               self::PATH_RECIPIENT_ESTIMATIONS . '/' . $campaignId,
                query:         $query?->toArray() ?: null,
                returnRequest: $returnRequest,
            );
        } catch (ClientException $e) {
            if ($e->getHttpStatus() === 404) {
                return null;
            }
            throw $e;
        }

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    // endregion

    protected function apiPath(): string
    {

        return 'campaigns';
    }
}
