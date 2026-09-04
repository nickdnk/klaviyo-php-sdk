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
            new Response(200, ['RateLimit-Limit' => '10, 10;w=1, 150;w=60', 'RateLimit-Remaining' => '149', 'RateLimit-Reset' => '42'], json_encode(['data' => []])),
            new Response(200, [], json_encode(['data' => []])),
            new Response(429, ['Retry-After' => '0', 'RateLimit-Limit' => '10'], json_encode(['errors' => []])),
            new Response(200, ['RateLimit-Limit' => '10', 'RateLimit-Remaining' => '1', 'RateLimit-Reset' => '1'], json_encode(['data' => []])),
        ]));
        $client = APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack($stack));

        self::assertNull($client->getLastRateLimit(), 'nothing before the first call');

        $client->lists->list();
        $rl = $client->getLastRateLimit();
        self::assertInstanceOf(RateLimit::class, $rl);
        self::assertSame([10, 149, 42, null, 200], [$rl->limit, $rl->remaining, $rl->resetSeconds, $rl->retryAfterSeconds, $rl->status]);
        self::assertSame([1 => 10, 60 => 150], $rl->windows, 'every window of the structured header is kept');
        self::assertSame(10, $rl->burstLimit());
        self::assertFalse($rl->isNearlyExhausted());

        $client->lists->list();
        self::assertSame($rl, $client->getLastRateLimit(), 'a response without the headers keeps the previous reading');

        $client->lists->list(); // 429 → retried transparently → final 200 with remaining=1
        $rl = $client->getLastRateLimit();
        self::assertSame(200, $rl->status);
        self::assertSame(1, $rl->remaining);
        self::assertTrue($rl->isNearlyExhausted());

    }

    /**
     * Klaviyo's live header values, taken from the recorded fixtures: the binding quota first,
     * then one entry per window; some endpoints add a daily window. A bare number must still work.
     */
    public function testStructuredLimitHeaderIsParsed(): void
    {

        $rl = RateLimit::fromResponse(new Response(200, ['RateLimit-Limit' => '1, 1;w=1, 2;w=60, 225;w=86400', 'RateLimit-Remaining' => '0', 'RateLimit-Reset' => '0']));
        self::assertSame(1, $rl->limit);
        self::assertSame([1 => 1, 60 => 2, 86400 => 225], $rl->windows);
        self::assertSame(1, $rl->burstLimit());
        self::assertSame(0, $rl->remaining);
        self::assertTrue($rl->isNearlyExhausted());

        $rl = RateLimit::fromResponse(new Response(200, ['RateLimit-Limit' => '75, 75;w=1, 750;w=60', 'RateLimit-Remaining' => '74']));
        self::assertSame(75, $rl->burstLimit());
        self::assertSame(750, $rl->windows[60]);

        $rl = RateLimit::fromResponse(new Response(200, ['RateLimit-Limit' => '150', 'RateLimit-Remaining' => '3']));
        self::assertSame(150, $rl->limit);
        self::assertSame([], $rl->windows);
        self::assertSame(150, $rl->burstLimit(), 'falls back to the plain limit when no windows are given');

        $rl = RateLimit::fromResponse(new Response(200, ['RateLimit-Limit' => '10;w=1, 150;w=60', 'RateLimit-Remaining' => '9']));
        self::assertSame(10, $rl->limit, 'without a leading bare quota the smallest window quota is the limit');

    }

    public function testFromResponseReturnsNullWithoutHeaders(): void
    {

        self::assertNull(RateLimit::fromResponse(new Response(204)));
        $rl = RateLimit::fromResponse(new Response(429, ['Retry-After' => '7']));
        self::assertSame(7, $rl->retryAfterSeconds);
        self::assertNull($rl->limit);

    }

    public function testRateLimitParserSkipsGarbageParts(): void
    {

        $rl = RateLimit::fromResponse(new Response(200, ['RateLimit-Limit' => '10, , n/a;w=1, 150;w=60, 3;w=abc', 'RateLimit-Remaining' => '9']));
        self::assertSame(10, $rl->limit);
        self::assertSame([60 => 150], $rl->windows, 'empty and non-numeric parts are skipped; a window without a numeric w= is not a window');
        self::assertSame(150, $rl->burstLimit());

    }

}
