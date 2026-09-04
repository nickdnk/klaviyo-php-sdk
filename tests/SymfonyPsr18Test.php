<?php


namespace nickdnk\Klaviyo\Tests;

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Http\Psr18Transport;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException as SymfonyInvalidArgument;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface as SymfonyResponse;

/**
 * The SDK driven by a third-party PSR-18 implementation, Symfony's {@see Psr18Client} over its
 * own {@see MockHttpClient}. Nothing here is written by this project: the client, its PSR-7
 * responses and its exception classes all come from Symfony, so these tests are what proves the
 * transport really is swappable rather than fitted to the fakes elsewhere in the suite.
 *
 * Symfony's client also serves as the PSR-17 request and stream factory, since Psr18Client
 * implements those interfaces too.
 */
#[Group('psr18')]
class SymfonyPsr18Test extends TestCase
{

    /** @var list<float> */
    private array $slept = [];

    /**
     * @param list<MockResponse>|callable $responses
     */
    private function client(array|callable $responses, int $maxAttempts = 10): APIClient
    {

        $this->slept = [];
        $psr18 = new Psr18Client(new MockHttpClient($responses));
        $retry = new RetryPolicy(maxAttempts: $maxAttempts, jitterFactor: 0, sleep: function (float $s) { $this->slept[] = $s; });

        return new APIClient('tkn', new Psr18Transport($psr18, $psr18, $psr18), $retry);

    }

    private static function json(mixed $data, int $status = 200, array $headers = []): MockResponse
    {

        return new MockResponse(json_encode(['data' => $data]), [
            'http_code'        => $status,
            'response_headers' => $headers + ['content-type' => 'application/vnd.api+json'],
        ]);

    }

    public function testGetSendsAuthenticatedRequestAndHydratesTheResponse(): void
    {

        $response = self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIPs']]);

        $list = $this->client([$response])->lists->get('L1', (new Query())->fields('list', 'name'));

        self::assertSame('VIPs', $list->name);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://a.klaviyo.com/api/lists/L1?fields%5Blist%5D=name', $response->getRequestUrl());

        $headers = [];
        foreach ($response->getRequestOptions()['headers'] as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $headers[strtolower($name)] = $value;
        }

        self::assertSame('Bearer tkn', $headers['authorization']);
        self::assertSame(APIClient::API_REVISION, $headers['revision']);
        self::assertSame('application/vnd.api+json', $headers['accept']);

    }

    public function testCreateSendsTheJsonApiEnvelopeAsTheRequestBody(): void
    {

        $response = self::json(['type' => 'list', 'id' => 'L2', 'attributes' => ['name' => 'New']], 201);

        $created = $this->client([$response])->lists->create(new CreateList('New'));

        self::assertSame('L2', $created->id);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(
            ['data' => ['type' => 'list', 'attributes' => ['name' => 'New', 'opt_in_process' => 'single_opt_in']]],
            json_decode($response->getRequestOptions()['body'], true)
        );

    }

    /**
     * PSR-18 forbids treating an error status as a failure, and Symfony honours that: the 400
     * arrives as a response, and the errors array is parsed off its body.
     */
    public function testErrorStatusArrivesAsAResponseAndBecomesAClientException(): void
    {

        $response = new MockResponse(
            json_encode(['errors' => [['code' => 'invalid', 'title' => 'Invalid input.', 'detail' => 'List name is required.', 'status' => 400]]]),
            ['http_code' => 400]
        );

        try {
            $this->client([$response])->lists->get('L1');
            self::fail('a 400 must throw');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame('List name is required.', $e->getFirstError()->detail);
            self::assertSame('invalid', $e->getFirstError()->code);
        }

    }

    public function testRateLimitedResponseIsRetriedAfterRetryAfter(): void
    {

        $first = new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '3']]);
        $second = self::json([]);

        $result = $this->client([$first, $second])->lists->list();

        self::assertSame([], $result['data']);
        self::assertSame([3.0], $this->slept);

    }

    /**
     * Symfony wraps a transport failure as Psr18NetworkException, which implements PSR-18's
     * NetworkExceptionInterface, so the retry policy resends it and gives up as a
     * ConnectionException carrying Symfony's own exception.
     */
    public function testTransportFailureIsRetriedThenSurfacesAsConnectionException(): void
    {

        $attempts = 0;
        $client = $this->client(
            function () use (&$attempts): SymfonyResponse {
                $attempts++;
                throw new TransportException('connection reset by peer');
            },
            maxAttempts: 3
        );

        try {
            $client->lists->list();
            self::fail('a transport failure must surface');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(\Psr\Http\Client\NetworkExceptionInterface::class, $e->getPrevious());
            self::assertStringContainsString('connection reset by peer', $e->getPrevious()->getMessage());
        }

        self::assertSame(3, $attempts);
        self::assertSame([2.0, 4.0], $this->slept);

    }

    /**
     * An unusable request is Psr18RequestException instead, which is final on the first attempt:
     * resending cannot make an invalid request valid.
     */
    public function testInvalidRequestFailureIsNotRetried(): void
    {

        $attempts = 0;
        $client = $this->client(
            function () use (&$attempts): SymfonyResponse {
                $attempts++;
                throw new SymfonyInvalidArgument('unsupported option');
            }
        );

        try {
            $client->lists->list();
            self::fail('an invalid request must surface');
        } catch (ConnectionException $e) {
            self::assertInstanceOf(\Psr\Http\Client\RequestExceptionInterface::class, $e->getPrevious());
        }

        self::assertSame(1, $attempts);
        self::assertSame([], $this->slept);

    }

    /**
     * The pool falls back to sequential sends on a PSR-18 transport, but its results, ordering
     * and per-request error handling are the same as on Guzzle.
     */
    public function testPoolRunsSequentiallyAndKeepsInputOrder(): void
    {

        $client = $this->client([
            self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'First']]),
            new MockResponse(json_encode(['errors' => [['detail' => 'gone', 'status' => 410]]]), ['http_code' => 410]),
            self::json(['type' => 'list', 'id' => 'L3', 'attributes' => ['name' => 'Third']]),
        ]);

        $results = $client->executePool([
            $client->lists->get('L1', returnRequest: true),
            $client->lists->get('L2', returnRequest: true),
            $client->lists->get('L3', returnRequest: true),
        ]);

        self::assertSame('First', $results[0]->name);
        self::assertInstanceOf(ClientException::class, $results[1]);
        self::assertSame(410, $results[1]->getHttpStatus());
        self::assertSame('Third', $results[2]->name);

    }

}
