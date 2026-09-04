<?php


namespace nickdnk\Klaviyo\Services\Traits;

use JsonSerializable;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\MultipartBody;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;
use Psr\Http\Message\RequestInterface;

/**
 * The endpoints every Klaviyo bulk-job family shares, keyed by the family's path
 * (`profile-bulk-import-jobs`, `catalog-item-bulk-create-jobs`, …). A service can host several
 * families, so the path is an argument rather than state:
 *
 *  - `POST {path}`                           submit a job, answered with the job resource
 *  - `GET  {path}`                           list jobs
 *  - `GET  {path}/{job_id}`                  one job
 *  - `GET  {path}/{job_id}/{relation}`       resources attached to a job
 *  - `GET  {path}/{job_id}/relationships/…`  their identifiers
 *
 * These helpers carry no `@link`: the service method that wraps a family
 * (`profiles->bulkImport()`, `catalogItems->bulkCreate()`, …) links the reference for it.
 */
trait HasBulkJobs
{

    /**
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    abstract protected function request(string $method, string $endpoint, array|JsonSerializable|MultipartBody|null $body = null, ?array $query = null, bool $returnRequest = false): RequestInterface|array|null;

    /**
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function bulkJobSubmit(string $path, TypedResource $job, bool $returnRequest = false): IdentifiableResource|RequestInterface
    {

        $result = $this->request('POST', $path, $job, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    /**
     * @return array{data: IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function bulkJobList(string $path, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->request('GET', $next ?? $path, query: $next === null ? ($query?->toArray() ?: null) : null, returnRequest: $returnRequest);

    }

    /**
     * Null when the job id is unknown.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function bulkJobGet(string $path, string $jobId, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        try {
            $result = $this->request('GET', $path . '/' . $jobId, query: $query?->toArray() ?: null, returnRequest: $returnRequest);
        } catch (ClientException $e) {
            if ($e->getHttpStatus() === 404) {
                return null;
            }
            throw $e;
        }

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    /**
     * Resources attached to one job, e.g. the lists, profiles or import errors of a bulk import job.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function bulkJobRelated(string $path, string $jobId, string $relation, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->request('GET', $next ?? $path . '/' . $jobId . '/' . $relation, query: $next === null ? ($query?->toArray() ?: null) : null, returnRequest: $returnRequest);

    }

    /**
     * Identifiers of the resources attached to one job.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function bulkJobRelatedIds(string $path, string $jobId, string $relation, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->request('GET', $next ?? $path . '/' . $jobId . '/relationships/' . $relation, query: $next === null ? ($query?->toArray() ?: null) : null, returnRequest: $returnRequest);

    }

}
