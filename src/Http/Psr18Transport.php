<?php


namespace nickdnk\Klaviyo\Http;

use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Any PSR-18 client plus PSR-17 factories. Concurrency is sequential, since PSR-18 has no
 * asynchronous send; bulk syncs still work, they just run one request at a time.
 */
final class Psr18Transport implements Transport
{

    public function __construct(
        private readonly ClientInterface         $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface  $streamFactory,
    ) {}

    /**
     * Builds the transport around a PSR-18 client, picking up PSR-17 factories from
     * nyholm/psr7 or guzzlehttp/psr7 when none are given.
     */
    public static function create(ClientInterface $client, ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null
    ): self
    {

        if ($requestFactory === null || $streamFactory === null) {
            $factory = self::discoverFactory();
            $requestFactory ??= $factory;
            $streamFactory ??= $factory;
        }

        return new self($client, $requestFactory, $streamFactory);

    }

    /**
     * @return RequestFactoryInterface&StreamFactoryInterface
     */
    public static function discoverFactory(): RequestFactoryInterface&StreamFactoryInterface
    {

        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            return new \Nyholm\Psr7\Factory\Psr17Factory();
        }
        if (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            return new \GuzzleHttp\Psr7\HttpFactory();
        }

        throw new LogicException(
            'No PSR-17 factory found. Install nyholm/psr7 or guzzlehttp/psr7, or pass request and stream factories explicitly.'
        );

    }

    public function send(RequestInterface $request): ResponseInterface
    {

        return $this->client->sendRequest($request);

    }

    /**
     * PSR-18 only defines the blocking {@see ClientInterface::sendRequest()}, so `$concurrency`
     * cannot be honoured: requests go out one at a time, in order. The retry rounds and result
     * ordering in the client are unaffected.
     */
    public function sendConcurrently(iterable $requests, int $concurrency, callable $onResponse, callable $onError): void
    {

        foreach ($requests as $key => $request) {
            try {
                $onResponse($key, $request, $this->client->sendRequest($request));
            } catch (Throwable $e) {
                $onError($key, $request, $e);
            }
        }

    }

    public function requestFactory(): RequestFactoryInterface
    {

        return $this->requestFactory;

    }

    public function streamFactory(): StreamFactoryInterface
    {

        return $this->streamFactory;

    }

}
