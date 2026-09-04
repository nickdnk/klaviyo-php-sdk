<?php


namespace nickdnk\Klaviyo\Http;

use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
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
final readonly class Psr18Transport implements Transport
{

    public function __construct(
        private ClientInterface         $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface  $streamFactory,
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
     * A PSR-17 factory found among the installed packages. Only the two implementations that
     * expose requests and streams from one class are looked for, because that is what this
     * returns; nyholm/psr7 comes first because a project that has Guzzle installed gets
     * {@see GuzzleTransport} as its default and never reaches this.
     *
     * Any other implementation (laminas/laminas-diactoros, httpsoft/http-message, slim/psr7, …)
     * splits the two factories across classes, so pass them to {@see self::create()} instead of
     * relying on this. Symfony's `Psr18Client` is both factories itself and can be passed for
     * both.
     *
     * @return RequestFactoryInterface&StreamFactoryInterface
     * @throws LogicException when neither package is installed
     */
    public static function discoverFactory(): RequestFactoryInterface&StreamFactoryInterface
    {

        if (class_exists(Psr17Factory::class)) {
            return new Psr17Factory();
        }
        if (class_exists(HttpFactory::class)) {
            return new HttpFactory();
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
     *
     * A conforming client throws nothing but {@see \Psr\Http\Client\ClientExceptionInterface},
     * yet this catches {@see Throwable}: one request must not be able to discard the results of
     * every other request in the batch.
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
