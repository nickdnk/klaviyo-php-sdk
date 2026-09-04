<?php


namespace nickdnk\Klaviyo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Http\Psr18Transport;
use nickdnk\Klaviyo\OAuthCredentials;
use nickdnk\Klaviyo\TokenExchange;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\MultipartBody;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Profile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The client against a bare PSR-18 implementation, no Guzzle anywhere in the path: headers,
 * bodies, status mapping, retries and the pool must all live in the client, not the transport.
 */
#[Group('psr18')]
class Psr18TransportTest extends TestCase
{

    /** @var ClientInterface&object{sent: list<RequestInterface>} */
    private ClientInterface $fake;

    /** @var list<float> */
    private array $slept = [];

    /**
     * Minimal PSR-18 client: answers from a script in order (throwing scripted exceptions) and
     * records every request it was handed in `$sent`.
     *
     * @param list<ResponseInterface|\Throwable> $script
     * @return ClientInterface&object{sent: list<RequestInterface>}
     */
    private static function fakeClient(array $script): ClientInterface
    {

        return new class($script) implements ClientInterface {

            /** @var list<RequestInterface> */
            public array $sent = [];

            public function __construct(private array $script) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {

                $this->sent[] = $request;
                $next = array_shift($this->script) ?? throw new RuntimeException('Fake client script exhausted.');
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;

            }

        };

    }

    /**
     * @param list<ResponseInterface|NetworkExceptionInterface> $script answers in order; exceptions are thrown
     */
    private function client(array $script, int $maxAttempts = 10): APIClient
    {

        $this->slept = [];
        $this->fake = self::fakeClient($script);

        $retry = new RetryPolicy(maxAttempts: $maxAttempts, jitterFactor: 0, sleep: function (float $s) { $this->slept[] = $s; });

        return APIClient::withAccessToken('tkn', Psr18Transport::create($this->fake, new Psr17Factory(), new Psr17Factory()), $retry);

    }

    private static function json(mixed $data, int $status = 200, array $headers = []): Response
    {

        return new Response($status, $headers, json_encode(['data' => $data]));

    }

    private static function networkFailure(): NetworkExceptionInterface
    {

        return new class('connection reset') extends RuntimeException implements NetworkExceptionInterface {

            public function getRequest(): RequestInterface
            {

                return (new Psr17Factory())->createRequest('GET', 'https://a.klaviyo.com/api/');

            }

        };

    }

    public function testListBuildsAuthenticatedJsonApiRequestAndHydrates(): void
    {

        $client = $this->client([
            self::json([['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP']]]),
        ]);

        $result = $client->lists->list((new Query())->fields('list', 'name')->pageSize(3));

        $request = $this->fake->sent[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://a.klaviyo.com/api/lists?fields%5Blist%5D=name&page%5Bsize%5D=3', (string)$request->getUri());
        self::assertSame('Bearer tkn', $request->getHeaderLine('Authorization'));
        self::assertSame('2026-07-15', $request->getHeaderLine('revision'));
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));
        self::assertSame('nickdnk/klaviyo-php-sdk', $request->getHeaderLine('User-Agent'));
        self::assertInstanceOf(KlaviyoList::class, $result['data'][0]);
        self::assertSame('VIP', $result['data'][0]->name);

    }

    public function testCreateSendsJsonBody(): void
    {

        $client = $this->client([self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP']], 201)]);

        $list = $client->lists->create(new CreateList('VIP'));

        $request = $this->fake->sent[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            ['data' => ['type' => 'list', 'attributes' => ['name' => 'VIP', 'opt_in_process' => 'single_opt_in']]],
            json_decode((string)$request->getBody(), true)
        );
        self::assertSame('L1', $list->id);

    }

    public function testStatusesMapToExceptions(): void
    {

        $client = $this->client([
            new Response(400, [], json_encode(['errors' => [['code' => 'invalid', 'detail' => 'Bad email']]])),
            new Response(502, [], 'upstream down'),
            new Response(404, [], json_encode(['errors' => [['detail' => 'nope']]])),
        ]);

        try {
            $client->lists->list();
            self::fail('400 must throw.');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('Bad email', $e->getErrors()[0]->detail);
            self::assertSame('Bad email', $e->getFirstError()?->detail);
            self::assertSame('Bad email', $e->getRawErrors()[0]['detail']);
            self::assertSame('Klaviyo client error (HTTP 400): Bad email', $e->getMessage());
            self::assertSame('GET', $e->getRequest()->getMethod());
            self::assertSame(400, $e->getResponse()->getStatusCode());
        }

        try {
            $client->lists->list();
            self::fail('502 must throw.');
        } catch (ServerException $e) {
            self::assertSame(502, $e->getHttpStatus());
        }

        self::assertNull($client->lists->get('missing'), '404 on get() stays null through the PSR-18 path.');

    }

    public function testRateLimitRetriesHonourRetryAfterThenBackoff(): void
    {

        $client = $this->client([
            self::json([], 429, ['Retry-After' => '3']),
            self::json([], 503),
            self::json(['type' => 'profile', 'id' => 'p1', 'attributes' => ['email' => 'a@b.test']]),
        ]);

        $profile = $client->profiles->get('p1');

        self::assertInstanceOf(Profile::class, $profile);
        self::assertCount(3, $this->fake->sent);
        self::assertSame([3.0, 4.0], $this->slept, 'Retry-After wins on the first retry; exponential backoff (2s × 2^(attempt-1)) on the second.');

    }

    public function testRateLimitGivesUpAfterMaxAttempts(): void
    {

        $client = $this->client([
            self::json([], 429, ['Retry-After' => '0']),
            self::json([], 429, ['Retry-After' => '0']),
            self::json([], 429, ['Retry-After' => '0']),
        ], maxAttempts: 2);

        try {
            $client->profiles->list();
            self::fail('Exhausted retries must surface the last 429.');
        } catch (ClientException $e) {
            self::assertSame(429, $e->getHttpStatus());
        }

        self::assertCount(2, $this->fake->sent);
        self::assertSame([0.0], $this->slept);

    }

    public function testNetworkFailureRetriesThenBecomesConnectionException(): void
    {

        $client = $this->client([
            self::networkFailure(),
            self::json(['type' => 'profile', 'id' => 'p1', 'attributes' => ['email' => 'a@b.test']]),
        ]);
        self::assertSame('p1', $client->profiles->get('p1')->id);
        self::assertSame([2.0], $this->slept);

        $client = $this->client([self::networkFailure(), self::networkFailure()], maxAttempts: 2);
        try {
            $client->profiles->get('p1');
            self::fail('Exhausted network retries must throw.');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(NetworkExceptionInterface::class, $e->getPrevious());
        }

    }

    public function testNonNetworkTransportFailureIsNotRetried(): void
    {

        $failure = new class('bad request object') extends RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
        $client = $this->client([$failure]);

        $this->expectException(ConnectionException::class);
        $client->profiles->list();

    }

    public function testRefreshCallbackRunsOnceOn401(): void
    {

        $received = [];
        $fakeScript = [
            new Response(401, [], json_encode(['errors' => [['detail' => 'expired']]])),
            new Response(200, [], json_encode(['access_token' => 'fresh', 'refresh_token' => 'rt2', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'profiles:read'])),
            self::json(['type' => 'profile', 'id' => 'p1', 'attributes' => ['email' => 'a@b.test']]),
        ];
        $fake = self::fakeClient($fakeScript);
        $client = APIClient::withOAuth(
            new OAuthCredentials('stale', 'rt1', time() + 3600), 'cid', 'sec',
            function (OAuthCredentials $c, TokenExchange $exchange) use (&$received) {
                return $received[] = $exchange($c);
            },
            Psr18Transport::create($fake)
        );

        self::assertSame('p1', $client->profiles->get('p1')->id);
        self::assertCount(1, $received);
        self::assertSame('fresh', $received[0]->accessToken);
        self::assertSame('rt2', $received[0]->refreshToken);
        self::assertSame('Bearer stale', $fake->sent[0]->getHeaderLine('Authorization'));
        self::assertSame('https://a.klaviyo.com/oauth/token', (string)$fake->sent[1]->getUri(), 'Refresh goes through the same transport.');
        self::assertSame('Bearer fresh', $fake->sent[2]->getHeaderLine('Authorization'), 'The retry is rebuilt with the refreshed token.');

    }

    public function testApiKeyClientSendsKlaviyoApiKeyHeaderAndNeverRefreshes(): void
    {

        $fake = self::fakeClient([
            self::json([['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP']]]),
            new Response(401, [], json_encode(['errors' => [['detail' => 'Invalid API key']]])),
        ]);
        $client = APIClient::withApiKey('pk_secret', Psr18Transport::create($fake), new RetryPolicy(jitterFactor: 0, sleep: fn() => null));

        self::assertSame('VIP', $client->lists->list()['data'][0]->name);
        self::assertSame('Klaviyo-API-Key pk_secret', $fake->sent[0]->getHeaderLine('Authorization'));
        self::assertSame('2026-07-15', $fake->sent[0]->getHeaderLine('revision'));

        try {
            $client->lists->list();
            self::fail('401 with an API key must surface, there is nothing to refresh.');
        } catch (ClientException $e) {
            self::assertSame(401, $e->getHttpStatus());
        }
        self::assertCount(2, $fake->sent, 'No refresh retry was attempted.');

    }

    public function testPoolRunsSequentiallyAndRetriesInRounds(): void
    {

        $client = $this->client([
            self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'a']]),
            self::json([], 429, ['Retry-After' => '1']),
            new Response(400, [], json_encode(['errors' => [['detail' => 'bad']]])),
            self::json(['type' => 'list', 'id' => 'L2', 'attributes' => ['name' => 'b']]),
        ]);

        $requests = (function () use ($client) {
            foreach (['L1', 'L2', 'L3'] as $id) {
                yield $client->lists->get($id, returnRequest: true);
            }
        })();

        $results = $client->executePool($requests, 5);

        self::assertCount(3, $results);
        self::assertSame('a', $results[0]->name);
        self::assertSame('b', $results[1]->name, 'The 429 at index 1 was resent in a second round and slotted back in order.');
        self::assertInstanceOf(ClientException::class, $results[2]);
        self::assertSame([1.0], $this->slept);
        self::assertSame(
            ['/api/lists/L1', '/api/lists/L2', '/api/lists/L3', '/api/lists/L2'],
            array_map(fn(RequestInterface $r) => $r->getUri()->getPath(), $this->fake->sent)
        );

    }

    public function testMultipartBodyIsEncodedWithoutGuzzle(): void
    {

        $client = $this->client([self::json(['type' => 'image', 'id' => 'i1', 'attributes' => ['name' => 'a']])]);
        $makeRequest = (new ReflectionMethod($client, 'makeRequest'))->getClosure($client);

        $makeRequest('POST', 'image-upload', (new MultipartBody())->withFile('file', 'PNGBYTES', 'a "quoted".png', 'image/png')->withField('hidden', 'true'));

        $request = $this->fake->sent[0];
        self::assertMatchesRegularExpression('#^multipart/form-data; boundary=[0-9a-f]{40}$#', $request->getHeaderLine('Content-Type'));
        $boundary = substr($request->getHeaderLine('Content-Type'), strlen('multipart/form-data; boundary='));
        self::assertSame(
            "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"a \\\"quoted\\\".png\"\r\n"
            . "Content-Type: image/png\r\n"
            . "\r\nPNGBYTES\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"hidden\"\r\n"
            . "\r\ntrue\r\n"
            . "--{$boundary}--\r\n",
            (string)$request->getBody()
        );

    }

    public function testOAuthTokenExchangeOverPsr18(): void
    {

        $fake = self::fakeClient([
            new Response(200, [], json_encode(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'x'])),
            new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'expired'])),
        ]);
        $transport = Psr18Transport::create($fake);

        $tokens = APIClient::refreshAccessToken('cid', 'sec', 'rt', $transport);
        self::assertInstanceOf(OAuthCredentials::class, $tokens);
        self::assertSame('a', $tokens->accessToken);
        self::assertSame('r', $tokens->refreshToken);
        self::assertSame('x', $tokens->scope);
        self::assertGreaterThanOrEqual(time() + 3590, $tokens->expiresAt);
        self::assertSame('https://a.klaviyo.com/oauth/token', (string)$fake->sent[0]->getUri());
        self::assertSame('Basic ' . base64_encode('cid:sec'), $fake->sent[0]->getHeaderLine('Authorization'));
        self::assertSame('application/x-www-form-urlencoded', $fake->sent[0]->getHeaderLine('Content-Type'));
        self::assertSame('grant_type=refresh_token&refresh_token=rt', (string)$fake->sent[0]->getBody());

        try {
            APIClient::refreshAccessToken('cid', 'sec', 'rt', $transport);
            self::fail('400 from the token endpoint must be an OAuthException.');
        } catch (OAuthException $e) {
            self::assertTrue($e->isInvalidGrant());
        }

    }


    /** Discovery order is nyholm/psr7, then guzzlehttp/psr7; one given factory replaces only that half. */
    public function testCreateDiscoversPsr17FactoriesWhenNoneAreGiven(): void
    {

        $fake = self::fakeClient([
            self::json([['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP']]]),
        ]);

        $transport = Psr18Transport::create($fake);
        self::assertInstanceOf(Psr17Factory::class, $transport->requestFactory(), 'nyholm/psr7 is discovered');
        self::assertInstanceOf(Psr17Factory::class, $transport->streamFactory());

        $result = APIClient::withAccessToken('tkn', $transport)->lists->list();
        self::assertInstanceOf(KlaviyoList::class, $result['data'][0], 'requests built with the discovered factory round-trip');
        self::assertSame('Bearer tkn', $fake->sent[0]->getHeaderLine('Authorization'));

        $custom = new class extends Psr17Factory {};
        $half = Psr18Transport::create($fake, requestFactory: $custom);
        self::assertSame($custom, $half->requestFactory(), 'explicit factory is kept');
        self::assertNotSame($custom, $half->streamFactory(), 'the missing half is discovered');
        self::assertInstanceOf(Psr17Factory::class, $half->streamFactory());

        self::assertInstanceOf(Psr17Factory::class, Psr18Transport::discoverFactory());

    }

    public function testPsr18PoolReportsThrowingClientAsError(): void
    {

        $failing = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {

                throw new RuntimeException('socket closed');

            }
        };
        $client = APIClient::withAccessToken('t', Psr18Transport::create($failing), new RetryPolicy(maxAttempts: 1, jitterFactor: 0, sleep: fn() => null));

        $results = $client->executePool([$client->lists->get('L1', returnRequest: true)]);
        self::assertInstanceOf(ConnectionException::class, $results[0]);
        self::assertSame('socket closed', $results[0]->getPrevious()->getMessage());

    }

    // endregion

}
