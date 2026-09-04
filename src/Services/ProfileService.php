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

/**
 * Profiles are the people in an account: their identifiers, consent, and the lists, segments and
 * push tokens attached to them. This service also hosts the bulk families — import,
 * subscribe/unsubscribe, suppress/unsuppress — and the data privacy deletion request.
 *
 * - Creating a profile whose email already exists answers 409 with the existing id in
 *   `errors[0].meta.duplicate_profile_id`; {@see self::import()} upserts instead.
 * - {@see self::merge()} and every bulk job are asynchronous, so a merged source id keeps
 *   resolving for a while afterwards. Only the import family has a job to poll.
 * - {@see self::unsubscribe()} unsubscribes globally for any profile that is not in the list you
 *   name, so check membership first.
 * - {@see self::bulkImport()} takes up to 10 000 profiles, {@see self::suppress()} up to 100
 *   email addresses, and suppression jobs accept profiles, a list id or a segment id, never a
 *   combination.
 *
 * @link https://developers.klaviyo.com/en/reference/profiles_api_overview
 */
class ProfileService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_IMPORT             = 'profile-import';
    private const string PATH_MERGE              = 'profile-merge';
    private const string PATH_BULK_IMPORT_JOBS   = 'profile-bulk-import-jobs';
    private const string PATH_SUBSCRIBE_JOBS     = 'profile-subscription-bulk-create-jobs';
    private const string PATH_UNSUBSCRIBE_JOBS   = 'profile-subscription-bulk-delete-jobs';
    private const string PATH_SUPPRESS_JOBS      = 'profile-suppression-bulk-create-jobs';
    private const string PATH_UNSUPPRESS_JOBS    = 'profile-suppression-bulk-delete-jobs';
    private const string PATH_DELETION_JOBS      = 'data-privacy-deletion-jobs';

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
     * Klaviyo has no lookup by external id; this filters the collection and takes the first match.
     *
     * @link https://developers.klaviyo.com/en/reference/get_profiles
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

        $result = $this->request('POST', self::PATH_IMPORT, $profile, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    /**
     * Merges the source profiles into the destination and deletes them.
     *
     * @link https://developers.klaviyo.com/en/reference/merge_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function merge(ProfileMerge $merge, bool $returnRequest = false): Profile|RequestInterface
    {

        $result = $this->request('POST', self::PATH_MERGE, $merge, returnRequest: $returnRequest);

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

        return $this->bulkJobSubmit(self::PATH_BULK_IMPORT_JOBS, $job, $returnRequest);

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

        return $this->bulkJobList(self::PATH_BULK_IMPORT_JOBS, $query, $next, $returnRequest);

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

        return $this->bulkJobGet(self::PATH_BULK_IMPORT_JOBS, $jobId, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_errors_for_bulk_import_profiles_job
     * @return array{data: ImportError[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkImportJobErrors(string $jobId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobRelated(self::PATH_BULK_IMPORT_JOBS, $jobId, 'import-errors', $query, $next, $returnRequest);

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

        return $this->bulkJobRelated(self::PATH_BULK_IMPORT_JOBS, $jobId, 'lists', $query, returnRequest: $returnRequest);

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

        return $this->bulkJobRelatedIds(self::PATH_BULK_IMPORT_JOBS, $jobId, 'lists', returnRequest: $returnRequest);

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

        return $this->bulkJobRelated(self::PATH_BULK_IMPORT_JOBS, $jobId, 'profiles', $query, $next, $returnRequest);

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

        return $this->bulkJobRelatedIds(self::PATH_BULK_IMPORT_JOBS, $jobId, 'profiles', $query, $next, $returnRequest);

    }

    // endregion

    // region Subscriptions

    /**
     * Records consent for up to 1000 profiles, and adds them to the job's list when one is attached.
     * The job runs asynchronously and has no status endpoint.
     *
     * Klaviyo rejects the whole batch with a 400 when a phone number is valid but outside the regions
     * the account can send to, one error per profile pointing at
     * `/data/attributes/profiles/data/{i}/attributes/phone_number`. Dropping those profiles' SMS
     * consent and resubmitting is an application decision; `KlaviyoError::indexIn()` gives the indexes.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_subscribe_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function subscribe(SubscriptionCreateJob $job, bool $returnRequest = false): ?RequestInterface
    {

        $result = $this->request('POST', self::PATH_SUBSCRIBE_JOBS, $job, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : null;

    }

    /**
     * Removes consent for up to 100 profiles: from the job's list when one is attached, otherwise
     * account-wide. Same phone-region rejection as subscribe().
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_unsubscribe_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function unsubscribe(SubscriptionDeleteJob $job, bool $returnRequest = false): ?RequestInterface
    {

        $result = $this->request('POST', self::PATH_UNSUBSCRIBE_JOBS, $job, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : null;

    }

    // endregion

    // region Suppressions

    /**
     * Suppresses up to 100 profiles from email marketing, by email address, list or segment.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_suppress_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function suppress(RequestSuppressionCreateJob $job, bool $returnRequest = false): SuppressionCreateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_SUPPRESS_JOBS, $job, $returnRequest);

    }

    /**
     * Lifts up to 100 manual suppressions, by email address, list or segment.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_unsuppress_profiles
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function unsuppress(RequestSuppressionDeleteJob $job, bool $returnRequest = false): SuppressionDeleteJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_UNSUPPRESS_JOBS, $job, $returnRequest);

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

        return $this->bulkJobList(self::PATH_SUPPRESS_JOBS, $query, $next, $returnRequest);

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

        return $this->bulkJobGet(self::PATH_SUPPRESS_JOBS, $jobId, $query, $returnRequest);

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

        return $this->bulkJobList(self::PATH_UNSUPPRESS_JOBS, $query, $next, $returnRequest);

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

        return $this->bulkJobGet(self::PATH_UNSUPPRESS_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    /**
     * Identify the profile by id, email or phone number; the two identifiers are mutually exclusive.
     *
     * @link https://developers.klaviyo.com/en/reference/request_profile_deletion
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function deleteProfile(DataPrivacyDeletionJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', self::PATH_DELETION_JOBS, $job, returnRequest: $returnRequest);

    }

    protected function apiPath(): string
    {
        return 'profiles';
    }
}
