<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use Psr\Http\Message\RequestInterface;

/**
 * The JSON:API relationship trio every Klaviyo resource exposes:
 *
 *  - `GET  {apiPath}/{id}/{relation}`                → full related resource(s)
 *  - `GET  {apiPath}/{id}/relationships/{relation}`  → resource identifiers (type + id) only
 *  - `POST | PATCH | DELETE {apiPath}/{id}/relationships/{relation}` → add / replace / remove linkage
 *
 * Services wrap these in typed public methods (`profiles()`, `tagIds()`, `addProfiles()`, …).
 */
trait HasRelationships
{

    abstract protected function apiPath(): string;

    /**
     * Related resource(s). `data` is a list for to-many relations and a single resource for
     * to-one relations (e.g. an event's profile); both hydrate through the type registry.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function relatedTrait(string $id, string $relation, ?Query $query = null, ?string $next = null,
        bool $returnRequest = false
    ): array|RequestInterface
    {

        return $this->request(
                           'GET',
                           $next ?? $this->apiPath() . '/' . $id . '/' . $relation,
            query:         $next === null ? ($query?->toArray() ?: null) : null,
            returnRequest: $returnRequest,
        );

    }

    /**
     * Resource identifiers only. Each entry hydrates to its response class with just `id`
     * set, since the payload carries no attributes.
     *
     * @return array{data: IdentifiableResource|IdentifiableResource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function relatedIdsTrait(string $id, string $relation, ?Query $query = null, ?string $next = null,
        bool $returnRequest = false
    ): array|RequestInterface
    {

        return $this->request(
                           'GET',
                           $next ?? $this->apiPath() . '/' . $id . '/relationships/' . $relation,
            query:         $next === null ? ($query?->toArray() ?: null) : null,
            returnRequest: $returnRequest,
        );

    }

    /**
     * To-one variant of {@see self::relatedTrait()} (an event's profile, a push token's
     * profile). Null when the relation is empty or the parent id is unknown (404).
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function relatedOneTrait(string $id, string $relation, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        return $this->fetchOne($this->apiPath() . '/' . $id . '/' . $relation, $query, $returnRequest);

    }

    /**
     * To-one variant of {@see self::relatedIdsTrait()}: the related identifier with only `id` set.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function relatedOneIdTrait(string $id, string $relation, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        return $this->fetchOne($this->apiPath() . '/' . $id . '/relationships/' . $relation, null, $returnRequest);

    }

    /**
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function fetchOne(string $path, ?Query $query, bool $returnRequest): IdentifiableResource|RequestInterface|null
    {

        try {
            $result = $this->request('GET', $path, query: $query?->toArray() ?: null, returnRequest: $returnRequest);
        } catch (ClientException $e) {
            if ($e->getHttpStatus() === 404) {
                return null;
            }
            throw $e;
        }

        if ($result instanceof RequestInterface || $result === null) {
            return $result;
        }

        return $result['data'] ?? null;

    }

    /**
     * @param IdentifiableResource[] $resources identifiers (type + id) to link
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function addRelatedTrait(string $id, string $relation, array $resources, bool $returnRequest = false): ?RequestInterface
    {

        return $this->mutateRelationship('POST', $id, $relation, $resources, $returnRequest);

    }

    /**
     * Replaces the full set of linked resources.
     *
     * @param IdentifiableResource[] $resources identifiers (type + id) that make up the new set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function replaceRelatedTrait(string $id, string $relation, array $resources, bool $returnRequest = false): ?RequestInterface
    {

        return $this->mutateRelationship('PATCH', $id, $relation, $resources, $returnRequest);

    }

    /**
     * @param IdentifiableResource[] $resources identifiers (type + id) to unlink
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function removeRelatedTrait(string $id, string $relation, array $resources, bool $returnRequest = false): ?RequestInterface
    {

        return $this->mutateRelationship('DELETE', $id, $relation, $resources, $returnRequest);

    }

    /**
     * @param IdentifiableResource[] $resources
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    private function mutateRelationship(string $method, string $id, string $relation, array $resources, bool $returnRequest): ?RequestInterface
    {

        $result = $this->request(
                           $method,
                           $this->apiPath() . '/' . $id . '/relationships/' . $relation,
                           array_values($resources),
            returnRequest: $returnRequest,
        );

        return $result instanceof RequestInterface ? $result : null;

    }

}
