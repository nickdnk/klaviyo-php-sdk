<?php


namespace nickdnk\Klaviyo\Services;

use Closure;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;
use Psr\Http\Message\RequestInterface;

/**
 * One Klaviyo bulk-job family (`profile-bulk-import-jobs`, `catalog-item-bulk-create-jobs`, …).
 * Every family exposes the same three operations, so services hand out one of these per
 * family via {@see Traits\HasBulkJobs::bulkJobs()} instead of hand-writing them:
 *
 *  - `POST {path}`           submit a job, answered with the job resource (202)
 *  - `GET  {path}`           list jobs (filter / fields / sort / page)
 *  - `GET  {path}/{job_id}`  one job, optionally with `include` / `fields`
 *
 * @template T of IdentifiableResource
 */
final class BulkJobs
{

    /**
     * @param Closure(string, string, mixed, ?array, bool): (RequestInterface|array|null) $request the owning
     *                                                                                    service's request()
     */
    public function __construct(private readonly Closure $request, private readonly string $path) {}

    /**
     * @return T|RequestInterface|null
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function submit(TypedResource $job, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        $result = ($this->request)('POST', $this->path, $job, null, $returnRequest);

        if ($result instanceof RequestInterface || $result === null) {
            return $result;
        }

        return $result['data'];

    }

    /**
     * @return array{data: T[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return ($this->request)('GET', $next ?? $this->path, null, $query?->toArray() ?: null, $returnRequest);

    }

    /**
     * `GET {path}/{job_id}/{relation}`: resources attached to one job, e.g. the `lists`,
     * `profiles` or `import-errors` of a profile bulk import job.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function related(string $jobId, string $relation, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return ($this->request)(
            'GET',
            $next ?? $this->path . '/' . $jobId . '/' . $relation,
            null,
            $next === null ? ($query?->toArray() ?: null) : null,
            $returnRequest
        );

    }

    /**
     * `GET {path}/{job_id}/relationships/{relation}`: identifiers only.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function relatedIds(string $jobId, string $relation, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return ($this->request)(
            'GET',
            $next ?? $this->path . '/' . $jobId . '/relationships/' . $relation,
            null,
            $next === null ? ($query?->toArray() ?: null) : null,
            $returnRequest
        );

    }

    /**
     * Null when the job id is unknown (404).
     *
     * @return T|RequestInterface|null
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $jobId, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        try {
            $result = ($this->request)('GET', $this->path . '/' . $jobId, null, $query?->toArray() ?: null, $returnRequest);
        } catch (ClientException $e) {
            if ($e->getHttpStatus() === 404) {
                return null;
            }
            throw $e;
        }

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

}
