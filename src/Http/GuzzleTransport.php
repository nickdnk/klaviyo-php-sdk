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
 * Guzzle-backed transport, and what {@see \nickdnk\Klaviyo\APIClient} picks by default when
 * Guzzle is installed, because Guzzle's pool gives bulk work real concurrency where plain
 * PSR-18 cannot.
 *
 * Sends go through Guzzle's own {@see GuzzleClientInterface::send()} rather than
 * {@see \Psr\Http\Client\ClientInterface::sendRequest()}, because the pool needs `sendAsync()`,
 * which PSR-18 has no equivalent for, and both paths then share one set of options. For PSR-18
 * semantics against Guzzle, hand a Guzzle client to {@see Psr18Transport::create()} instead.
 *
 * Those options are `http_errors => false`, keeping status handling in the client, and
 * `allow_redirects => false`, which is what `sendRequest()` does and what keeps this transport
 * behaviourally identical to {@see Psr18Transport} (the Klaviyo API declares no 3xx).
 */
final readonly class GuzzleTransport implements Transport
{

    private const array SEND_OPTIONS = [
        RequestOptions::HTTP_ERRORS     => false,
        RequestOptions::ALLOW_REDIRECTS => false,
    ];

    public function __construct(
        private GuzzleClientInterface   $client,
        private RequestFactoryInterface $requestFactory = new HttpFactory(),
        private StreamFactoryInterface  $streamFactory = new HttpFactory(),
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
