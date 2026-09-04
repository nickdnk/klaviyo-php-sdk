<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use Psr\Http\Message\RequestInterface;

trait HasGet
{

    abstract protected function apiPath(): string;

    /**
     * GET {apiPath}/{id}. Null when the id is unknown (404). `$query` carries sparse
     * fieldsets / includes; filter, sort and page are meaningless here.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function getTrait(string $id, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null
    {

        try {
            $result = $this->request(
                               'GET',
                               $this->apiPath() . '/' . $id,
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

    abstract public function get(string $id, ?Query $query = null, bool $returnRequest = false): IdentifiableResource|RequestInterface|null;

}
