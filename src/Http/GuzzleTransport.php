<?php


namespace nickdnk\Klaviyo\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Guzzle-backed transport. Only loaded when Guzzle is installed; it is what
 * {@see \nickdnk\Klaviyo\APIClient} picks by default in that case, because
 * Guzzle's pool gives the bulk sync real concurrency where plain PSR-18 cannot.
 *
 * Every send passes `http_errors => false`: status handling belongs to the client, so 4xx
 * and 5xx come back as responses instead of Guzzle exceptions.
 */
final class GuzzleTransport implements Transport
{

    private const array SEND_OPTIONS = [RequestOptions::HTTP_ERRORS => false];

    public function __construct(
        private readonly GuzzleClientInterface   $client,
        private readonly RequestFactoryInterface $requestFactory = new HttpFactory(),
        private readonly StreamFactoryInterface  $streamFactory = new HttpFactory(),
    ) {}

    /**
     * Production defaults: 10 s connect, 30 s total. `$config` is merged over them, so tests
     * pass a `handler` and callers can override timeouts.
     */
    public static function create(array $config = []): self
    {

        return new self(new Client($config + [
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT         => 30,
        ]));

    }

    /**
     * Routes every request through `$stack` (typically a MockHandler) instead of the network.
     */
    public static function fromHandlerStack(HandlerStack $stack): self
    {

        return self::create(['handler' => $stack]);

    }

    public function send(RequestInterface $request): ResponseInterface
    {

        return $this->client->send($request, self::SEND_OPTIONS);

    }

    public function sendConcurrently(iterable $requests, int $concurrency, callable $onResponse, callable $onError): void
    {

        $promises = (function () use ($requests, $onResponse, $onError) {
            foreach ($requests as $key => $request) {
                yield $key => fn() => $this->client
                    ->sendAsync($request, self::SEND_OPTIONS)
                    ->then(
                        fn(ResponseInterface $response) => $onResponse($key, $request, $response),
                        fn(Throwable $reason) => $onError($key, $request, $reason),
                    );
            }
        })();

        (new Pool($this->client, $promises, ['concurrency' => $concurrency]))->promise()->wait();

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
