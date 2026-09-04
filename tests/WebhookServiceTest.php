<?php


namespace nickdnk\Klaviyo\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateWebhook;
use nickdnk\Klaviyo\Resources\Request\UpdateWebhook;
use nickdnk\Klaviyo\Resources\Response\Webhook;
use nickdnk\Klaviyo\Resources\Shared\Explicit;
use nickdnk\Klaviyo\Resources\Shared\WebhookTopic;
use PHPUnit\Framework\TestCase;

/**
 * WebhookService CRUD against the responses recorded with the OAuth app (webhooks are 403 for
 * API keys, so the 403 fixture is asserted too).
 */
class WebhookServiceTest extends TestCase
{

    private MockHandler $mock;

    private function client(Response ...$responses): APIClient
    {

        $this->mock = new MockHandler($responses);

        return new APIClient('oauth-token', GuzzleTransport::fromHandlerStack(HandlerStack::create($this->mock)));

    }

    private function lastBody(): array
    {

        $this->mock->getLastRequest()->getBody()->rewind();

        return json_decode((string)$this->mock->getLastRequest()->getBody(), true);

    }

    public function testCreateGetUpdateDelete(): void
    {

        $client = $this->client(
            Fixtures::response('create_webhook.201'),
            Fixtures::response('get_webhook.200'),
            Fixtures::response('get_webhooks.200'),
            Fixtures::response('update_webhook.200'),
            Fixtures::response('delete_webhook.204'),
        );

        $created = $client->webhooks->create(new CreateWebhook('orders', 'https://example.com/hook', 'secret', [WebhookTopic::OPENED_EMAIL, 'event:api.viewed_product'], 'desc'), (new Query())->fields('webhook', 'name', 'enabled'));
        self::assertSame('POST', $this->mock->getLastRequest()->getMethod());
        self::assertSame('/api/webhooks', $this->mock->getLastRequest()->getUri()->getPath());
        $body = $this->lastBody();
        self::assertSame(['name' => 'orders', 'endpoint_url' => 'https://example.com/hook', 'secret_key' => 'secret', 'description' => 'desc'], $body['data']['attributes']);
        self::assertSame([['type' => 'webhook-topic', 'id' => 'event:klaviyo.opened_email'], ['type' => 'webhook-topic', 'id' => 'event:api.viewed_product']], $body['data']['relationships']['webhook-topics']['data']);
        self::assertInstanceOf(Webhook::class, $created);
        self::assertSame('01M1PAC5N9PMXJ7672AC8B75VN', $created->id);
        self::assertTrue($created->enabled);
        self::assertCount(3, $created->getRelationship('webhook-topics')->data);

        $hook = $client->webhooks->get($created->id, (new Query())->include('webhook-topics'));
        self::assertSame('/api/webhooks/01M1PAC5N9PMXJ7672AC8B75VN', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(Webhook::class, $hook);
        self::assertStringStartsWith('https://', $hook->endpoint_url);
        $topics = $hook->getRelationship('webhook-topics');
        self::assertContainsOnlyInstancesOf(WebhookTopic::class, $topics->data);
        self::assertTrue($topics->data[0]->isSystemTopic() || $topics->data[0]->integration() !== null);

        $page = $client->webhooks->list((new Query())->include('webhook-topics'));
        self::assertContainsOnlyInstancesOf(Webhook::class, $page['data']);
        self::assertNotEmpty($page['data'][0]->getRelationship('webhook-topics')->ids());

        $update = new UpdateWebhook($created->id);
        $update->name = 'orders-renamed';
        $update->description = Explicit::null();
        $update->enabled = false;
        $update->setTopics([WebhookTopic::BOUNCED_EMAIL]);
        $updated = $client->webhooks->update($update);
        self::assertSame('PATCH', $this->mock->getLastRequest()->getMethod());
        $body = $this->lastBody();
        self::assertSame(['name' => 'orders-renamed', 'description' => null, 'enabled' => false], $body['data']['attributes']);
        self::assertSame([['type' => 'webhook-topic', 'id' => 'event:klaviyo.bounced_email']], $body['data']['relationships']['webhook-topics']['data']);
        self::assertInstanceOf(Webhook::class, $updated);

        self::assertNull($client->webhooks->delete($created->id));
        self::assertSame('DELETE', $this->mock->getLastRequest()->getMethod());
        self::assertSame(0, $this->mock->count());

    }

    public function testApiKeyGets403AndUnknownIdIsNull(): void
    {

        $client = $this->client(Fixtures::response('get_webhooks.403'), Fixtures::response('get_webhook.404'), Fixtures::response('create_webhook.403'));

        try {
            $client->webhooks->list();
            self::fail('403 expected');
        } catch (ClientException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertStringContainsString('Advanced KDP', $e->getMessage());
        }

        self::assertNull($client->webhooks->get('NOPE01'));

        try {
            $client->webhooks->create(new CreateWebhook('x', 'https://example.com', 's', [WebhookTopic::OPENED_EMAIL]));
            self::fail('403 expected');
        } catch (ClientException $e) {
            self::assertSame(403, $e->getHttpStatus());
        }

    }

}
