<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use Psr\Http\Message\RequestInterface;

trait HasDelete
{

    abstract protected function apiPath(): string;

    /**
     * A 404 is swallowed: deleting a resource that is already gone leaves you where you wanted to
     * be, so this answers null either way. Every other error status throws.
     *
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    final public function delete(string $id, bool $returnRequest = false): ?RequestInterface
    {

        $url = $this->apiPath() . '/' . $id;

        if ($returnRequest) {
            return $this->request('DELETE', $url, returnRequest: true);
        }

        try {
            $this->request('DELETE', $url);
        } catch (ClientException $e) {
            if ($e->getHttpStatus() !== 404) {
                throw $e;
            }
        }

        return null;

    }

}
