<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEvents;
use nickdnk\Klaviyo\Resources\Request\BulkCreateEventsJob;
use nickdnk\Klaviyo\Resources\Request\CreateEvent;
use nickdnk\Klaviyo\Resources\Request\CreateEventForProfile;
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\Metric;
use nickdnk\Klaviyo\Resources\Response\Event;
use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use PHPUnit\Framework\TestCase;

class EventServiceTest extends TestCase
{

    private MockHandler $mock;

    private function client(Response ...$responses): APIClient
    {

        $this->mock = new MockHandler($responses);

        return APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack(HandlerStack::create($this->mock)));

    }

    private function lastBody(): array
    {

        $request = $this->mock->getLastRequest();
        self::assertNotNull($request, 'No request was sent.');

        // Stream::__toString() seeks to 0 itself, so no explicit rewind() is needed.
        return json_decode((string)$request->getBody(), true);

    }

    public function testCreateEventBody(): void
    {

        $client = $this->client(Fixtures::response('create_event.202'));

        $profile = new ImportProfile();
        $profile->email = 'jane@example.com';
        $profile->properties = ['plan' => 'pro'];
        $metric = new Metric('Placed Order');
        $metric->service = 'shop';
        $event = new CreateEventForProfile($metric, $profile, ['sku' => 'A1', 'qty' => 2]);
        $event->unique_id = 'order-1';
        $event->value = 12.5;
        $event->value_currency = 'EUR';
        $event->time = '2026-09-04T12:00:00+00:00';

        self::assertNull($client->events->create($event));
        self::assertSame('POST', $this->mock->getLastRequest()->getMethod());
        self::assertSame('/api/events', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertSame([
            'data' => [
                'type'       => 'event',
                'attributes' => [
                    'metric'         => ['data' => ['type' => 'metric', 'attributes' => ['name' => 'Placed Order', 'service' => 'shop']]],
                    'properties'     => ['sku' => 'A1', 'qty' => 2],
                    'profile'        => ['data' => ['type' => 'profile', 'attributes' => ['email' => 'jane@example.com', 'properties' => ['plan' => 'pro']]]],
                    'unique_id'      => 'order-1',
                    'value'          => 12.5,
                    'value_currency' => 'EUR',
                    'time'           => '2026-09-04T12:00:00+00:00',
                ],
            ],
        ], $this->lastBody());

    }

    public function testBulkCreateBody(): void
    {

        $client = $this->client(Fixtures::response('bulk_create_events.202'));

        $profile = new CreateProfile();
        $profile->email = 'jane@example.com';
        $first = new CreateEvent(new Metric('Viewed Product'), ['sku' => 'A1']);
        $second = new CreateEvent(new Metric('Added To Cart'), ['sku' => 'A1']);
        $second->value = 3.0;

        self::assertNull($client->events->bulkCreate(new BulkCreateEventsJob([new BulkCreateEvents($profile, [$first, $second])])));
        self::assertSame('/api/event-bulk-create-jobs', $this->mock->getLastRequest()->getUri()->getPath());

        $body = $this->lastBody();
        self::assertSame('event-bulk-create-job', $body['data']['type']);
        $entry = $body['data']['attributes']['events-bulk-create']['data'][0];
        self::assertSame('event-bulk-create', $entry['type']);
        self::assertSame('jane@example.com', $entry['attributes']['profile']['data']['attributes']['email']);
        self::assertCount(2, $entry['attributes']['events']['data']);
        self::assertSame('Added To Cart', $entry['attributes']['events']['data'][1]['attributes']['metric']['data']['attributes']['name']);
        self::assertEquals(3, $entry['attributes']['events']['data'][1]['attributes']['value'], 'json_encode writes 3.0 as 3');
        self::assertArrayNotHasKey('value', $entry['attributes']['events']['data'][0]['attributes']);

    }

    public function testListAndGetHydrateRecordedEvents(): void
    {

        $client = $this->client(Fixtures::response('get_events.200'), Fixtures::response('get_event.200'), Fixtures::response('get_event.400'));

        $page = $client->events->list((new Query())->filter(Filter::equals('metric_id', 'SkDfHe'))->sort('datetime', descending: true)->pageSize(2));
        self::assertSame('/api/events', $this->mock->getLastRequest()->getUri()->getPath());
        parse_str($this->mock->getLastRequest()->getUri()->getQuery(), $q);
        self::assertSame('equals(metric_id,"SkDfHe")', $q['filter']);
        self::assertSame('-datetime', $q['sort']);
        self::assertNotEmpty($page['data']);
        self::assertContainsOnlyInstancesOf(Event::class, $page['data']);
        self::assertNotNull($page['data'][0]->datetime);

        $event = $client->events->get('7qWkC6JPz7g', (new Query())->include('metric', 'profile'));
        self::assertInstanceOf(Event::class, $event);
        self::assertSame('7qWkC6JPz7g', $event->id);
        self::assertInstanceOf(AttributeBag::class, $event->event_properties);
        self::assertNotNull($event->getRelationship('profile'));
        self::assertNotNull($event->getRelationship('metric'));

        try {
            $client->events->get('NOPE01');
            self::fail('a malformed id is a 400, which the SDK must not turn into null');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getHttpStatus());
        }

    }

    public function testReturnRequestBuildsWithoutSending(): void
    {

        $client = $this->client();
        $profile = new ImportProfile();
        $profile->email = 'jane@example.com';

        $request = $client->events->create(new CreateEventForProfile(new Metric('X'), $profile, []), returnRequest: true);
        self::assertSame('POST', $request->getMethod());
        self::assertNull($this->mock->getLastRequest(), 'nothing was sent');

        $request = $client->events->bulkCreate(new BulkCreateEventsJob([]), returnRequest: true);
        self::assertSame('/api/event-bulk-create-jobs', $request->getUri()->getPath());

    }

}
