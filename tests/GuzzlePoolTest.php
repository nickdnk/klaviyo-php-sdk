<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;
use nickdnk\Klaviyo\Resources\Response\Profile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * MockHandler answers synchronously and so cannot show concurrency at all; these tests use an
 * asynchronous fake handler whose promises settle on a later tick, the way curl_multi's do.
 */
class GuzzlePoolTest extends TestCase
{

    private int $inFlight = 0;
    private int $maxInFlight = 0;
    private int $maxInFlightInRetryRound = 0;
    /** @var array<string, int> path => times sent */
    private array $sends = [];
    /** @var list<string> */
    private array $events = [];
    /** @var array<string, list<Response>> path => scripted answers */
    private array $script = [];

    private function client(): APIClient
    {

        $this->inFlight = $this->maxInFlight = $this->maxInFlightInRetryRound = 0;
        $this->events = [];
        $this->sends = [];
        $handler = function (RequestInterface $request): Promise {
            $path = $request->getUri()->getPath();
            $this->inFlight++;
            $this->maxInFlight = max($this->maxInFlight, $this->inFlight);
            $this->sends[$path] = ($this->sends[$path] ?? 0) + 1;
            if ($this->sends[$path] > 1) {
                $this->maxInFlightInRetryRound = max($this->maxInFlightInRetryRound, $this->inFlight);
            }
            $this->events[] = 'send ' . basename($path);
            $promise = new Promise(static function () { Utils::queue()->run(); });
            Utils::queue()->add(function () use ($promise, $path) {
                $this->inFlight--;
                $answers = &$this->script[$path];
                $next = $answers ? array_shift($answers) : self::ok(basename($path));
                $next instanceof \Throwable ? $promise->reject($next) : $promise->resolve($next);
            });

            return $promise;
        };

        return APIClient::withApiKey('pk', new GuzzleTransport(new Client(['handler' => HandlerStack::create($handler)])), new RetryPolicy(jitterFactor: 0, sleep: fn() => null));

    }

    private static function ok(string $id): Response
    {

        return new Response(200, [], json_encode(['data' => ['type' => 'profile', 'id' => $id, 'attributes' => ['email' => "{$id}@example.com"]]]));

    }

    public function testLazyPoolBuildsRequestsOnDemandAndBoundsConcurrency(): void
    {

        $client = $this->client();
        $ids = array_map(fn(int $i) => sprintf('p%02d', $i), range(1, 20));
        $built = 0;

        $results = $client->executePoolLazy($ids, function (string $id) use ($client, &$built) {
            $built++;
            $this->events[] = "build {$id}";
            $patch = new PatchProfile($id);
            $patch->properties = ['n' => $built];

            return $client->profiles->update($patch, returnRequest: true);
        }, concurrency: 5);

        self::assertCount(20, $results);
        self::assertSame($ids, array_map(fn(Profile $p) => $p->id, $results), 'input order preserved');
        self::assertSame(5, $this->maxInFlight, 'never more than `concurrency` requests in flight');
        self::assertSame(20, $built);
        self::assertSame(
            ['build p01', 'send p01', 'build p02', 'send p02', 'build p03', 'send p03'],
            array_slice($this->events, 0, 6),
            'each request is built right before the pool sends it, not all up front',
        );

    }

    public function testRetryRoundsResendOnlyTheFailedRequestsOneAtATime(): void
    {

        $client = $this->client();
        $this->script['/api/profiles/p2'] = [new Response(429, ['Retry-After' => '0'], ''), self::ok('p2')];
        $this->script['/api/profiles/p3'] = [new Response(404, [], json_encode(['errors' => [['detail' => 'gone']]]))];
        $this->script['/api/profiles/p4'] = [new Response(429, ['Retry-After' => '0'], ''), self::ok('p4')];
        $this->script['/api/profiles/p5'] = [new Response(503, [], ''), self::ok('p5')];

        $results = $client->executePool(array_map(fn(string $id) => $client->profiles->get($id, returnRequest: true), ['p1', 'p2', 'p3', 'p4', 'p5']), concurrency: 5);

        self::assertSame(['p1', 'p2', 'p4', 'p5'], array_map(fn(Profile $p) => $p->id, [$results[0], $results[1], $results[3], $results[4]]), 'retried entries resolve in place');
        self::assertInstanceOf(ClientException::class, $results[2]);
        self::assertSame(404, $results[2]->getHttpStatus(), 'a non-retryable 4xx is final and keeps its slot');
        self::assertSame(['send p1', 'send p2', 'send p3', 'send p4', 'send p5', 'send p2', 'send p4', 'send p5'], $this->events, 'the second round contains only the retryable requests');
        self::assertSame(5, $this->maxInFlight, 'first round used the full concurrency');

        // Re-run the retry round alone to observe its concurrency: it must be 1 so a burst limit is not tripped again.
        $client = $this->client();
        foreach (['p1', 'p2', 'p3'] as $id) {
            $this->script["/api/profiles/{$id}"] = [new Response(429, ['Retry-After' => '0'], ''), self::ok($id)];
        }
        $inFlightPerSend = [];
        $original = $this->events;
        $client->executePool(array_map(fn(string $id) => $client->profiles->get($id, returnRequest: true), ['p1', 'p2', 'p3']), concurrency: 3);
        self::assertSame(['send p1', 'send p2', 'send p3', 'send p1', 'send p2', 'send p3'], $this->events);
        self::assertSame(3, $this->maxInFlight, 'first round: 3 in flight');
        self::assertSame(1, $this->maxInFlightInRetryRound, 'retry round: one at a time');

    }


    public function testNetworkFailuresAreRetriedThenReportedAsConnectionExceptions(): void
    {

        $client = $this->client();
        $this->script['/api/profiles/p1'] = [
            new \GuzzleHttp\Exception\ConnectException('reset', new \GuzzleHttp\Psr7\Request('GET', '/api/profiles/p1')),
            self::ok('p1'),
        ];
        $this->script['/api/profiles/p2'] = array_fill(0, 10, new \GuzzleHttp\Exception\ConnectException('down', new \GuzzleHttp\Psr7\Request('GET', '/api/profiles/p2')));

        $results = $client->executePool([
            $client->profiles->get('p1', returnRequest: true),
            $client->profiles->get('p2', returnRequest: true),
        ], concurrency: 2);

        self::assertInstanceOf(Profile::class, $results[0], 'one transient failure, retried, succeeded');
        self::assertInstanceOf(\nickdnk\Klaviyo\Exceptions\ConnectionException::class, $results[1], 'persistent failure surfaces after the retry budget');
        self::assertSame('down', $results[1]->getPrevious()->getMessage());

    }

}
