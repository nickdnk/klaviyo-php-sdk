<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Http\RateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Klaviyo reports RateLimit-Limit / RateLimit-Remaining / RateLimit-Reset on every response and
 * Retry-After on 429; the client keeps the latest so bulk callers can pace themselves.
 */
class RateLimitTest extends TestCase
{

    public function testHeadersAreParsedAndKeptPerClient(): void
    {

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['RateLimit-Limit' => '150', 'RateLimit-Remaining' => '149', 'RateLimit-Reset' => '42'], json_encode(['data' => []])),
            new Response(200, [], json_encode(['data' => []])),
            new Response(429, ['Retry-After' => '0', 'RateLimit-Limit' => '10'], json_encode(['errors' => []])),
            new Response(200, ['RateLimit-Limit' => '10', 'RateLimit-Remaining' => '1', 'RateLimit-Reset' => '1'], json_encode(['data' => []])),
        ]));
        $client = APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack($stack));

        self::assertNull($client->getLastRateLimit(), 'nothing before the first call');

        $client->lists->list();
        $rl = $client->getLastRateLimit();
        self::assertInstanceOf(RateLimit::class, $rl);
        self::assertSame([150, 149, 42, null, 200], [$rl->limit, $rl->remaining, $rl->resetSeconds, $rl->retryAfterSeconds, $rl->status]);
        self::assertFalse($rl->isNearlyExhausted());

        $client->lists->list();
        self::assertSame($rl, $client->getLastRateLimit(), 'a response without the headers keeps the previous reading');

        $client->lists->list(); // 429 → retried transparently → final 200 with remaining=1
        $rl = $client->getLastRateLimit();
        self::assertSame(200, $rl->status);
        self::assertSame(1, $rl->remaining);
        self::assertTrue($rl->isNearlyExhausted());

    }

    public function testFromResponseReturnsNullWithoutHeaders(): void
    {

        self::assertNull(RateLimit::fromResponse(new Response(204)));
        $rl = RateLimit::fromResponse(new Response(429, ['Retry-After' => '7']));
        self::assertSame(7, $rl->retryAfterSeconds);
        self::assertNull($rl->limit);

    }

}
