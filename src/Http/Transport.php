<?php


namespace nickdnk\Klaviyo\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * What {@see \nickdnk\Klaviyo\APIClient} needs from an HTTP stack: a PSR-18 send,
 * PSR-17 factories to build requests, and a way to run several requests at once. Retries,
 * authentication, error mapping and JSON:API hydration all live in the client, so an
 * implementation only moves bytes. {@see Psr18Transport} wraps any PSR-18 client;
 * {@see GuzzleTransport} adds real concurrency through Guzzle's pool.
 */
interface Transport
{

    /**
     * Sends one request and returns whatever the server answered, 4xx and 5xx included.
     *
     * @throws ClientExceptionInterface on transport failure (DNS, connect, timeout, malformed request)
     */
    public function send(RequestInterface $request): ResponseInterface;

    /**
     * Sends requests with at most `$concurrency` in flight and reports each outcome through
     * a callback, keyed by the request's key in `$requests`. Requests are pulled from the
     * iterable lazily so a generator keeps only `$concurrency` request bodies in memory.
     *
     * @param iterable<array-key, RequestInterface>                         $requests
     * @param callable(array-key, RequestInterface, ResponseInterface): void $onResponse
     * @param callable(array-key, RequestInterface, Throwable): void         $onError
     */
    public function sendConcurrently(iterable $requests, int $concurrency, callable $onResponse, callable $onError): void;

    public function requestFactory(): RequestFactoryInterface;

    public function streamFactory(): StreamFactoryInterface;

}
