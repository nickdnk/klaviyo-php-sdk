<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkImportJob as RequestBulkImportJob;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;
use nickdnk\Klaviyo\Resources\Request\ProfileMerge;
use nickdnk\Klaviyo\Resources\Request\SubscriptionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SubscriptionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionCreateJob as RequestSuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Request\SuppressionDeleteJob as RequestSuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Response\BulkImportJob as ResponseBulkImportJob;
use nickdnk\Klaviyo\Resources\Response\Conversation;
use nickdnk\Klaviyo\Resources\Response\ImportError;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\PushToken;
use nickdnk\Klaviyo\Resources\Response\Segment;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Response\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasBulkJobs;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

class ProfileService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_BULK_IMPORT_JOBS  = 'profile-bulk-import-jobs';
    private const string PATH_SUPPRESS_JOBS     = 'profile-suppression-bulk-create-jobs';
    private const string PATH_UNSUPPRESS_JOBS   = 'profile-suppression-bulk-delete-jobs';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Profile|RequestInterface|null
    {
        return $this->getTrait($id, $query, $returnRequest);
    }

    /**
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getByExternalId(string $externalId, bool $returnRequest = false): Profile|RequestInterface|null
    {

        $result = $this->list((new Query())->filter(Filter::equals('external_id', $externalId)), returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : ($result['data'][0] ?? null);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profiles
     * @return array{data: Profile[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/create_profile
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateProfile $profile, ?Query $query = null, bool $returnRequest = false): Profile|RequestInterface
    {

        return $this->createTrait($profile, $query, $returnRequest);
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_profile
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(PatchProfile $profile, ?Query $query = null, bool $returnRequest = false): RequestInterface|Profile
    {

        return $this->updateTrait($profile, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_or_update_profile
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function import(ImportProfile $profile, bool $returnRequest = false): RequestInterface|Profile
    {

        $result = $this->request('POST', 'profile-import', $profile, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    /**
     * Merges the source profiles into the destination and deletes them. Returns the
     * surviving profile.
     *
     * @link https://developers.klaviyo.com/en/reference/merge_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function merge(ProfileMerge $merge, bool $returnRequest = false): Profile|RequestInterface
    {

        $result = $this->request('POST', 'profile-merge', $merge, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_lists_for_profile
     * @return array{data: KlaviyoList[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function lists(string $profileId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($profileId, 'lists', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_list_ids_for_profile
     * @return array{data: KlaviyoList[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function listIds(string $profileId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($profileId, 'lists', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_segments_for_profile
     * @return array{data: Segment[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function segments(string $profileId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($profileId, 'segments', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_segment_ids_for_profile
     * @return array{data: Segment[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function segmentIds(string $profileId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($profileId, 'segments', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_push_tokens_for_profile
     * @return array{data: PushToken[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function pushTokens(string $profileId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($profileId, 'push-tokens', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_push_token_ids_for_profile
     * @return array{data: PushToken[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function pushTokenIds(string $profileId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($profileId, 'push-tokens', returnRequest: $returnRequest);

    }

    /**
     * The profile's SMS conversation, if any.
     *
     * @link https://developers.klaviyo.com/en/reference/get_conversation_for_profile
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function conversation(string $profileId, ?Query $query = null, bool $returnRequest = false): Conversation|RequestInterface|null
    {

        return $this->relatedOneTrait($profileId, 'conversation', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_conversation_id_for_profile
     * @return Conversation|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function conversationId(string $profileId, bool $returnRequest = false): Conversation|RequestInterface|null
    {

        return $this->relatedOneIdTrait($profileId, 'conversation', $returnRequest);

    }

    /**
     * All of the profile's conversations across channels.
     *
     * @link https://developers.klaviyo.com/en/reference/get_conversations_for_profile
     * @return array{data: Conversation[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function conversations(string $profileId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($profileId, 'conversations', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_conversation_ids_for_profile
     * @return array{data: Conversation[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function conversationIds(string $profileId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($profileId, 'conversations', returnRequest: $returnRequest);

    }

    // endregion

    // region Bulk import jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_import_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkImport(RequestBulkImportJob $job, bool $returnRequest = false
    ): ResponseBulkImportJob|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->submit($job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_import_profiles_jobs
     * @return array{data: ResponseBulkImportJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->list($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_import_profiles_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJob(string $jobId, ?Query $query = null, bool $returnRequest = false): ResponseBulkImportJob|RequestInterface|null
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->get($jobId, $query, $returnRequest);

    }

    /**
     * Rows Klaviyo rejected from a bulk import job, paginated.
     *
     * @link https://developers.klaviyo.com/en/reference/get_errors_for_bulk_import_profiles_job
     * @return array{data: ImportError[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobErrors(string $jobId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->related($jobId, 'import-errors', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_list_for_bulk_import_profiles_job
     * @return array{data: KlaviyoList[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobLists(string $jobId, ?Query $query = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->related($jobId, 'lists', $query, returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_list_ids_for_bulk_import_profiles_job
     * @return array{data: KlaviyoList[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobListIds(string $jobId, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->relatedIds($jobId, 'lists', returnRequest: $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profiles_for_bulk_import_profiles_job
     * @return array{data: Profile[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobProfiles(string $jobId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->related($jobId, 'profiles', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_ids_for_bulk_import_profiles_job
     * @return array{data: Profile[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobProfileIds(string $jobId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_BULK_IMPORT_JOBS)->relatedIds($jobId, 'profiles', $query, $next, $returnRequest);

    }

    // endregion

    // region Subscriptions

    /**
     * Records consent for up to 1000 profiles (and adds them to the job's list, if any).
     * Answered 202 with an empty body; the job runs asynchronously and has no status endpoint.
     *
     * Klaviyo rejects the whole batch (400, one `KlaviyoError` per offending profile with a
     * pointer like `/data/attributes/profiles/data/3/attributes/phone_number`) when a phone number
     * is valid but not in a region the account can send to. Whether to drop those profiles' SMS
     * consent and resubmit is an application decision; `KlaviyoError::indexIn('/data/attributes/profiles/data')`
     * gives the offending indexes if you choose to.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_subscribe_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function subscribe(SubscriptionCreateJob $job, bool $returnRequest = false): ?RequestInterface
    {

        $result = $this->request('POST', 'profile-subscription-bulk-create-jobs', $job, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : null;

    }

    /**
     * Removes consent for up to 1000 profiles: scoped to the job's list when one is attached,
     * otherwise account-wide. Answered 202 with an empty body. Same phone-region rejection
     * behaviour as {@see self::subscribe()}.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_unsubscribe_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function unsubscribe(SubscriptionDeleteJob $job, bool $returnRequest = false): ?RequestInterface
    {

        $result = $this->request('POST', 'profile-subscription-bulk-delete-jobs', $job, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : null;

    }

    // endregion

    // region Suppressions

    /**
     * Manually suppresses profiles from email marketing. Klaviyo answers 202 with the job.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_suppress_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function suppress(RequestSuppressionCreateJob $job, bool $returnRequest = false): SuppressionCreateJob|RequestInterface|null
    {

        return $this->bulkJobs(self::PATH_SUPPRESS_JOBS)->submit($job, $returnRequest);

    }

    /**
     * Lifts manual suppressions. Klaviyo answers 202 with the job.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_unsuppress_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function unsuppress(RequestSuppressionDeleteJob $job, bool $returnRequest = false): SuppressionDeleteJob|RequestInterface|null
    {

        return $this->bulkJobs(self::PATH_UNSUPPRESS_JOBS)->submit($job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_suppress_profiles_jobs
     * @return array{data: SuppressionCreateJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getSuppressJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_SUPPRESS_JOBS)->list($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_suppress_profiles_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getSuppressJob(string $jobId, ?Query $query = null, bool $returnRequest = false): SuppressionCreateJob|RequestInterface|null
    {

        return $this->bulkJobs(self::PATH_SUPPRESS_JOBS)->get($jobId, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_unsuppress_profiles_jobs
     * @return array{data: SuppressionDeleteJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getUnsuppressJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobs(self::PATH_UNSUPPRESS_JOBS)->list($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_unsuppress_profiles_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getUnsuppressJob(string $jobId, ?Query $query = null, bool $returnRequest = false): SuppressionDeleteJob|RequestInterface|null
    {

        return $this->bulkJobs(self::PATH_UNSUPPRESS_JOBS)->get($jobId, $query, $returnRequest);

    }

    // endregion

    /**
     * User `phone_number` and `email` are mutually exclusive; provide only one, or set the Klaviyo User ID.
     *
     * @link https://developers.klaviyo.com/en/reference/request_profile_deletion
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function deleteProfile(DataPrivacyDeletionJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', 'data-privacy-deletion-jobs', $job, returnRequest: $returnRequest);

    }

    protected function apiPath(): string
    {
        return 'profiles';
    }
}
