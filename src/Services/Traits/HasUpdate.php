<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use Psr\Http\Message\RequestInterface;

trait HasUpdate
{

    abstract protected function apiPath(): string;

    /**
     * PATCH {apiPath}/{id}. The resource's own id selects the target. Returns the updated
     * resource when Klaviyo echoes it (200), or null for endpoints that answer 204. `$query`
     * carries the sparse fieldset (`fields[type]`) for that echo.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function updateTrait(IdentifiableResource $resource, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        $result = $this->request(
                           'PATCH',
                           $this->apiPath() . '/' . $resource->id,
                           $resource,
            query:         $query?->toArray() ?: null,
            returnRequest: $returnRequest,
        );

        if ($result instanceof RequestInterface || $result === null) {
            return $result;
        }

        return $result['data'];

    }

}
