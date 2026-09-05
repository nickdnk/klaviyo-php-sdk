<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Response\Coupon;
use nickdnk\Klaviyo\Resources\Response\Profile;
use PHPUnit\Framework\TestCase;

class PaginatorTest extends TestCase
{

    private array $history = [];

    private function client(array $responses): APIClient
    {

        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack($stack));

    }

    private static function page(array $ids, ?string $next): Response
    {

        return new Response(200, [], json_encode([
            'data'  => array_map(fn($id) => ['type' => 'profile', 'id' => $id, 'attributes' => ['email' => "{$id}@example.com"]], $ids),
            'links' => ['self' => 'https://a.klaviyo.com/api/profiles', 'next' => $next],
        ]));

    }

    public function testIterateWalksAllPagesLazilyAndKeepsTheQuery(): void
    {

        $client = $this->client([
            self::page(['p1', 'p2'], 'https://a.klaviyo.com/api/profiles?page%5Bsize%5D=2&page%5Bcursor%5D=c2'),
            self::page(['p3', 'p4'], 'https://a.klaviyo.com/api/profiles?page%5Bsize%5D=2&page%5Bcursor%5D=c3'),
            self::page(['p5'], null),
        ]);

        $items = $client->profiles->iterate((new Query())->pageSize(2)->fields('profile', 'email'));
        self::assertInstanceOf(\Generator::class, $items);
        self::assertCount(0, $this->history, 'nothing is fetched before iteration starts');

        $seen = [];
        foreach ($items as $i => $profile) {
            self::assertInstanceOf(Profile::class, $profile);
            $seen[$i] = $profile->id;
            if ($i === 2) {
                self::assertCount(2, $this->history, 'second page fetched only when the first was exhausted');
            }
        }

        self::assertSame(['p1', 'p2', 'p3', 'p4', 'p5'], $seen, 'running integer keys across pages');
        self::assertCount(3, $this->history);
        self::assertSame('/api/profiles?fields%5Bprofile%5D=email&page%5Bsize%5D=2', (string)$this->history[0]['request']->getUri()->withScheme('')->withHost(''), 'first page uses the Query');
        self::assertStringContainsString('page%5Bcursor%5D=c2', (string)$this->history[1]['request']->getUri(), 'following pages use links.next verbatim');
        self::assertStringContainsString('page%5Bcursor%5D=c3', (string)$this->history[2]['request']->getUri());

    }

    public function testBreakingOutStopsFetching(): void
    {

        $client = $this->client([
            self::page(['p1'], 'https://a.klaviyo.com/api/profiles?page%5Bcursor%5D=c2'),
            self::page(['p2'], null),
        ]);

        foreach ($client->profiles->iterate() as $profile) {
            break;
        }

        self::assertCount(1, $this->history, 'the second page was never requested');

    }

    public function testPagesAndAll(): void
    {

        $client = $this->client([
            self::page(['p1'], 'https://a.klaviyo.com/api/profiles?page%5Bcursor%5D=c2'),
            self::page(['p2', 'p3'], null),
        ]);

        $pages = iterator_to_array($client->paginate(fn(?string $next) => $client->profiles->list(null, $next))->pages());
        self::assertCount(2, $pages);
        self::assertSame('p1', $pages[0]['data'][0]->id);
        self::assertNull($pages[1]['links']->next);

        $client = $this->client([
            self::page(['p1'], 'https://a.klaviyo.com/api/profiles?page%5Bcursor%5D=c2'),
            self::page(['p2', 'p3'], null),
        ]);
        self::assertSame(['p1', 'p2', 'p3'], array_map(fn(Profile $p) => $p->id, iterator_to_array($client->profiles->iterate())));

    }

    public function testPaginateWrapsRelationshipListings(): void
    {

        $client = $this->client([
            self::page(['p1'], 'https://a.klaviyo.com/api/lists/L1/profiles?page%5Bcursor%5D=c2'),
            self::page(['p2'], null),
        ]);

        $ids = [];
        foreach ($client->paginate(fn(?string $next) => $client->lists->profiles('L1', (new Query())->pageSize(1), next: $next))->items() as $p) {
            $ids[] = $p->id;
        }

        self::assertSame(['p1', 'p2'], $ids);
        self::assertSame('/api/lists/L1/profiles', $this->history[0]['request']->getUri()->getPath());
        self::assertStringContainsString('cursor%5D=c2', (string)$this->history[1]['request']->getUri());

    }

    public function testRepeatedCursorStopsInsteadOfLooping(): void
    {

        $same = 'https://a.klaviyo.com/api/profiles?page%5Bcursor%5D=loop';
        $client = $this->client([
            self::page(['p1'], $same),
            self::page(['p2'], $same),   // server bug: hands the same cursor back
            self::page(['never'], null),
        ]);

        $ids = array_map(fn(Profile $p) => $p->id, iterator_to_array($client->profiles->iterate()));
        self::assertSame(['p1', 'p2'], $ids);
        self::assertCount(2, $this->history);

    }

    public function testErrorsPropagateFromTheFailingPage(): void
    {

        $client = $this->client([
            self::page(['p1'], 'https://a.klaviyo.com/api/profiles?page%5Bcursor%5D=c2'),
            new Response(400, [], json_encode(['errors' => [['detail' => 'bad cursor']]])),
        ]);

        $ids = [];
        try {
            foreach ($client->profiles->iterate() as $p) {
                $ids[] = $p->id;
            }
            self::fail('expected ClientException');
        } catch (ClientException $e) {
            self::assertSame(['p1'], $ids, 'items before the failure were yielded');
            self::assertSame(400, $e->getHttpStatus());
        }

    }

    public function testWorksOnARecordedPage(): void
    {

        // get_coupons.200 is a real single-item page with a live next cursor; a second, terminal page follows it.
        $last = Fixtures::response('get_coupons.200', function (array $body) {
            $body['links']['next'] = null;
            $body['data'][0]['id'] = 'second';
            return $body;
        });
        $client = $this->client([Fixtures::response('get_coupons.200'), $last]);

        $coupons = iterator_to_array($client->coupons->iterate((new Query())->pageSize(1)));
        self::assertCount(2, $coupons);
        self::assertContainsOnlyInstancesOf(Coupon::class, $coupons);
        self::assertSame('second', $coupons[1]->id);

    }

}
