<?php


namespace nickdnk\Klaviyo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Http\Psr18Transport;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * PSR-18 conformance from both sides. The fake client here is deliberately strict: it asserts
 * what PSR-18 lets a client assume about the request it is handed (an absolute URI, a body it
 * may read from the start), and it answers the way a conforming client must — 4xx and 5xx as
 * responses rather than exceptions, failures as {@see NetworkExceptionInterface} or
 * {@see RequestExceptionInterface}.
 *
 * It also answers with forward-only response bodies, which is what a real streaming client
 * hands back, so a second read of the same body would come up empty. That pins the client to
 * reading each response body once.
 */
#[Group('psr18')]
class Psr18ConformanceTest extends TestCase
{

    /**
     * A stream that can be read once and never seeked, like a live socket.
     */
    private static function forwardOnly(string $contents): StreamInterface
    {

        return new class($contents) implements StreamInterface {

            public int $reads = 0;

            private int $offset = 0;

            public function __construct(private readonly string $contents) {}

            public function __toString(): string
            {

                return $this->getContents();

            }

            public function getContents(): string
            {

                $this->reads++;
                $rest = substr($this->contents, $this->offset);
                $this->offset = strlen($this->contents);

                return $rest;

            }

            public function read(int $length): string
            {

                $chunk = substr($this->contents, $this->offset, $length);
                $this->offset += strlen($chunk);

                return $chunk;

            }

            public function isSeekable(): bool { return false; }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {

                throw new RuntimeException('Stream is not seekable.');

            }

            public function rewind(): void
            {

                throw new RuntimeException('Stream is not seekable.');

            }

            public function eof(): bool { return $this->offset >= strlen($this->contents); }

            public function tell(): int { return $this->offset; }

            public function getSize(): ?int { return strlen($this->contents); }

            public function isReadable(): bool { return true; }

            public function isWritable(): bool { return false; }

            public function write(string $string): int
            {

                throw new RuntimeException('Stream is not writable.');

            }

            public function close(): void {}

            public function detach() { return null; }

            public function getMetadata(?string $key = null) { return $key === null ? [] : null; }

        };

    }

    /**
     * @param list<ResponseInterface|\Throwable> $script
     * @return ClientInterface&object{sent: list<RequestInterface>, bodies: list<string>}
     */
    private static function strictClient(array $script): ClientInterface
    {

        return new class($script) implements ClientInterface {

            /** @var list<RequestInterface> */
            public array $sent = [];

            /** @var list<string> */
            public array $bodies = [];

            public function __construct(private array $script) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {

                $uri = $request->getUri();
                TestCase::assertSame('https', $uri->getScheme(), 'PSR-18 needs an absolute request URI');
                TestCase::assertNotSame('', $uri->getHost(), 'PSR-18 needs an absolute request URI');
                TestCase::assertSame($uri->getHost(), $request->getHeaderLine('Host'));

                $body = $request->getBody();
                TestCase::assertTrue($body->isReadable());
                TestCase::assertSame(0, $body->tell(), 'the body must be handed over unread');

                $this->sent[] = $request;
                $this->bodies[] = (string)$body;

                $next = array_shift($this->script) ?? throw new RuntimeException('Script exhausted.');
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;

            }

        };

    }

    /**
     * @param list<ResponseInterface|\Throwable> $script
     */
    private function client(array $script, ?ClientInterface &$fake = null): APIClient
    {

        $fake = self::strictClient($script);
        $retry = new RetryPolicy(maxAttempts: 3, jitterFactor: 0, sleep: static fn(float $s) => null);

        return new APIClient('tkn', Psr18Transport::create($fake, new Psr17Factory(), new Psr17Factory()), $retry);

    }

    public function testForwardOnlySuccessBodyIsReadExactlyOnce(): void
    {

        $stream = self::forwardOnly(json_encode(['data' => ['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIPs']]]));

        $list = $this->client([new Response(200, [], $stream)])->lists->get('L1');

        self::assertSame('VIPs', $list->name);
        self::assertSame(1, $stream->reads);

    }

    public function testForwardOnlyErrorBodyIsParsedFromTheSingleRead(): void
    {

        $stream = self::forwardOnly(json_encode(['errors' => [['code' => 'invalid', 'title' => 'Invalid input.', 'detail' => 'Bad name.', 'status' => 400]]]));

        try {
            $this->client([new Response(400, [], $stream)])->lists->get('L1');
            self::fail('a 400 must throw');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('Bad name.', $e->getFirstError()->detail);
            self::assertSame(1, $stream->reads);
        }

    }

    /**
     * A conforming client returns 4xx and 5xx instead of throwing, so nothing in the retry path
     * may depend on an exception to notice a failed status.
     */
    public function testRetryableStatusesComeBackAsResponsesAndAreResent(): void
    {

        $client = $this->client(
            [
                new Response(429, ['Retry-After' => '0']),
                new Response(200, [], json_encode(['data' => []])),
            ],
            $fake
        );

        $client->lists->list();

        self::assertCount(2, $fake->sent);

    }

    /**
     * The request body is rewound between attempts, so the second send carries the whole body
     * rather than the empty tail of an already-read stream.
     */
    public function testRequestBodyIsResentInFullAfterANetworkFailure(): void
    {

        $list = new CreateList('Retry me');

        $client = $this->client(
            [
                self::networkFailure(),
                new Response(201, [], json_encode(['data' => ['type' => 'list', 'id' => 'L2', 'attributes' => ['name' => 'Retry me']]])),
            ],
            $fake
        );

        $created = $client->lists->create($list);

        self::assertSame('L2', $created->id);
        self::assertCount(2, $fake->bodies);
        self::assertSame($fake->bodies[0], $fake->bodies[1]);
        self::assertStringContainsString('Retry me', $fake->bodies[1]);

    }

    /**
     * PSR-18 splits failures in two: a network failure may succeed on a resend, an invalid
     * request never will. Only the first is retried, and both surface as a ConnectionException.
     */
    public function testInvalidRequestFailureIsNotRetried(): void
    {

        $failure = new class('unsupported URI') extends RuntimeException implements RequestExceptionInterface {

            public function getRequest(): RequestInterface
            {

                return (new Psr17Factory())->createRequest('GET', 'https://a.klaviyo.com/api/lists');

            }

        };

        $client = $this->client([$failure], $fake);

        try {
            $client->lists->list();
            self::fail('a request failure must surface');
        } catch (ConnectionException $e) {
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertCount(1, $fake->sent, 'an invalid request must not be resent');

    }

    /**
     * Signature verification reads the body by casting the stream, which PSR-7 requires to seek
     * to the start first. A framework that parsed the body before handing the request over
     * therefore cannot leave the verification hashing an empty string.
     */
    public function testWebhookBodyVerifiesAfterTheFrameworkAlreadyReadIt(): void
    {

        $secret = 'whsec';
        $timestamp = gmdate('D, d M Y H:i:s \G\M\T');
        $payload = json_encode([
            'data' => [['type' => 'webhook-event', 'id' => 'E1', 'attributes' => ['topic' => 'event:klaviyo.metric.created']]],
            'meta' => [
                'klaviyo_webhook_id' => 'W1',
                'version'            => '2026-07-15',
                'timestamp'          => gmdate('c'),
            ],
        ]);

        $request = (new ServerRequest('POST', 'https://app.test/hooks', [
            'Klaviyo-Signature' => hash_hmac('sha256', $payload . $timestamp, $secret),
            'Klaviyo-Timestamp' => $timestamp,
        ], $payload));

        // What a framework leaves behind after parsing the body itself.
        self::assertSame($payload, (string)$request->getBody());

        $parsed = APIClient::parseWebhookRequest($request, $secret);

        self::assertNotNull($parsed);
        self::assertSame('W1', $parsed->webhookId);

    }

    /**
     * Without Guzzle installed there is nothing to fall back to, so a client built without a
     * transport says so instead of failing later at send time. Skipped while Guzzle is present,
     * where that same call legitimately picks {@see \nickdnk\Klaviyo\Http\GuzzleTransport}.
     */
    public function testClientWithoutATransportFailsFastWhenGuzzleIsAbsent(): void
    {

        if (class_exists(\GuzzleHttp\Client::class)) {
            self::markTestSkipped('Guzzle is installed and is picked up as the default transport.');
        }

        APIClient::setDefaultTransport(null);

        try {
            new APIClient('tkn');
            self::fail('a client without a transport must fail');
        } catch (\LogicException $e) {
            self::assertStringContainsString('No HTTP transport available', $e->getMessage());
        }

    }

    private static function networkFailure(): NetworkExceptionInterface
    {

        return new class('connection reset') extends RuntimeException implements NetworkExceptionInterface {

            public function getRequest(): RequestInterface
            {

                return (new Psr17Factory())->createRequest('GET', 'https://a.klaviyo.com/api/lists');

            }

        };

    }

}
