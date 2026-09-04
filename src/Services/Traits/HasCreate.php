<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;
use Psr\Http\Message\RequestInterface;

trait HasCreate
{

    abstract protected function apiPath(): string;

    /**
     * `$query` carries the sparse fieldset (`fields[type]`) for the echoed resource; Klaviyo
     * ignores filter / sort / page here.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function createTrait(TypedResource $resource, ?Query $query = null, bool $returnRequest = false): TypedResource|RequestInterface|null
    {

        $result = $this->request(
                           'POST',
                           $this->apiPath(),
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
