<?php


namespace nickdnk\Klaviyo\Services\Traits;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Resources\Shared\Resource;
use Psr\Http\Message\RequestInterface;

trait HasList
{

    abstract protected function apiPath(): string;

    /**
     * GET {apiPath}. `$next` is the `links.next` URL from a previous page and, when given,
     * already carries every query parameter, so `$query` is ignored for that request.
     *
     * @return array{data: Resource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    protected function listTrait(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->request(
                           'GET',
                           $next ?? $this->apiPath(),
            query:         $next === null ? ($query?->toArray() ?: null) : null,
            returnRequest: $returnRequest
        );

    }

    abstract public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface;

    /**
     * Every resource matching `$query`, fetched page by page as you iterate. Breaking out of the
     * loop stops the requests. Use `iterate($query)->all()` when the whole set is wanted at once,
     * or `->pages()` to see each page with its links.
     *
     * @return Paginator<Resource>
     */
    public function iterate(?Query $query = null): Paginator
    {

        return new Paginator(fn(?string $next) => $this->list($query, $next));

    }

}
