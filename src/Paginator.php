<?php


namespace nickdnk\Klaviyo;

use Closure;
use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;

/**
 * Walks Klaviyo's cursor pagination for you. Wrap any listing call that accepts a `next` URL:
 *
 * ```php
 * $paginator = $client->paginate(fn(?string $next) => $client->lists->profiles($listId, $query, next: $next));
 * foreach ($paginator->items() as $profile) { … }   // every profile, page after page
 * foreach ($paginator->pages() as $page) { … }      // ['data' => …, 'links' => …] per page
 * ```
 *
 * Pages are fetched lazily as the generator advances, so breaking out of the loop early stops
 * the requests. Services with `list()` also offer `iterate($query)`, which yields the items directly.
 *
 * @template T of IdentifiableResource
 */
final readonly class Paginator
{

    /** @var Closure(?string): array{data: T[], links: ?PaginationLinks} */
    private Closure $fetch;

    /**
     * @param callable(?string $next): array{data: T[], links: ?PaginationLinks} $fetch called with null for the
     *                                                                                first page and with `links.next`
     *                                                                                for every following one
     */
    public function __construct(callable $fetch)
    {

        $this->fetch = $fetch(...);

    }

    /**
     * Every page in order. Stops after the page whose `links.next` is null.
     *
     * @return Generator<int, array{data: T[], links: ?PaginationLinks}>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function pages(): Generator
    {

        $next = null;
        $seen = [];
        $index = 0;
        do {
            $page = ($this->fetch)($next);
            yield $index++ => $page;
            $next = $page['links']?->next;
            // A server that hands back a cursor it already served would loop forever; stop instead.
            if ($next !== null && isset($seen[$next])) {
                return;
            }
            if ($next !== null) {
                $seen[$next] = true;
            }
        } while ($next !== null);

    }

    /**
     * Every resource across all pages, with a running integer key.
     *
     * @return Generator<int, T>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function items(): Generator
    {

        $i = 0;
        foreach ($this->pages() as $page) {
            $data = $page['data'];
            foreach (is_array($data) ? $data : [$data] as $item) {
                yield $i++ => $item;
            }
        }

    }

    /**
     * Convenience for small result sets: all resources in one array.
     *
     * @return list<T>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function all(): array
    {

        return iterator_to_array($this->items(), false);

    }

}
