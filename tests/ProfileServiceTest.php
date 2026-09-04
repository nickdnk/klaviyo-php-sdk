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
use nickdnk\Klaviyo\Resources\Request\CreateProfile;
use nickdnk\Klaviyo\Resources\Request\DataPrivacyDeletionJob;
use nickdnk\Klaviyo\Resources\Request\EmailSubscription;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\MarketingConsent;
use nickdnk\Klaviyo\Resources\Request\PatchProfile;
use nickdnk\Klaviyo\Resources\Request\ProfileMetaPatch;
use nickdnk\Klaviyo\Resources\Request\PushSubscription;
use nickdnk\Klaviyo\Resources\Request\SmsSubscription;
use nickdnk\Klaviyo\Resources\Request\SubscribeProfile;
use nickdnk\Klaviyo\Resources\Request\SubscriptionChannels;
use nickdnk\Klaviyo\Resources\Request\SubscriptionDeleteJob;
use nickdnk\Klaviyo\Resources\Request\WhatsappSubscription;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Response\SuppressionDeleteJob;
use nickdnk\Klaviyo\Resources\Shared\ProfileLocation;
use PHPUnit\Framework\TestCase;

/**
 * ProfileService paths not covered elsewhere: create/update/import bodies and hydration, the
 * unsubscribe job with every channel, suppression job listings and data-privacy deletion.
 */
class ProfileServiceTest extends TestCase
{

    private MockHandler $mock;

    private function client(Response ...$responses): APIClient
    {

        $this->mock = new MockHandler($responses);

        return APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack(HandlerStack::create($this->mock)));

    }

    private function lastBody(): array
    {

        $this->mock->getLastRequest()->getBody()->rewind();

        return json_decode((string)$this->mock->getLastRequest()->getBody(), true);

    }

    public function testCreateUpdateImport(): void
    {

        $client = $this->client(Fixtures::response('create_profile.201'), Fixtures::response('create_profile.409'), Fixtures::response('update_profile.200'), Fixtures::response('create_or_update_profile.200'));

        $new = new CreateProfile();
        $new->email = 'jane@example.com';
        $new->first_name = 'Jane';
        $location = new ProfileLocation();
        $location->city = 'Copenhagen';
        $location->country = 'Denmark';
        $new->location = $location;
        $new->properties = ['plan' => 'pro'];

        $created = $client->profiles->create($new, (new Query())->additionalFields('profile', 'subscriptions'));
        self::assertSame('/api/profiles', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertSame('additional-fields%5Bprofile%5D=subscriptions', $this->mock->getLastRequest()->getUri()->getQuery());
        self::assertSame(['email' => 'jane@example.com', 'first_name' => 'Jane', 'location' => ['city' => 'Copenhagen', 'country' => 'Denmark'], 'properties' => ['plan' => 'pro']], $this->lastBody()['data']['attributes']);
        self::assertInstanceOf(Profile::class, $created);
        self::assertSame('01M1PACBNA2275H1C93R2CDK0M', $created->id);
        self::assertNotNull($created->email);
        self::assertNotNull($created->getRelationship('lists'));

        try {
            $client->profiles->create($new);
            self::fail('409 expected');
        } catch (ClientException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertSame('duplicate_profile', $e->getFirstError()->code);
            self::assertNotEmpty($e->getFirstError()->meta['duplicate_profile_id']);
        }

        $patch = new PatchProfile('01M1PAAR5HBCRHA3NN9NG5WRTA');
        $patch->first_name = 'Smokey';
        $patch->addMeta('patch_properties', new ProfileMetaPatch(append: ['tags' => 'z'], unset: 'plan'));
        $updated = $client->profiles->update($patch, (new Query())->fields('profile', 'first_name', 'properties'));
        self::assertSame('PATCH', $this->mock->getLastRequest()->getMethod());
        $body = $this->lastBody();
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $body['data']['id']);
        self::assertSame(['append' => ['tags' => 'z'], 'unset' => 'plan'], $body['data']['meta']['patch_properties']);
        self::assertInstanceOf(Profile::class, $updated);
        self::assertSame('Smokey', $updated->first_name);

        $import = new ImportProfile('01M1PAAR5HBCRHA3NN9NG5WRTA');
        $import->last_name = 'Imported';
        $imported = $client->profiles->import($import);
        self::assertSame('/api/profile-import', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $this->lastBody()['data']['id']);
        self::assertInstanceOf(Profile::class, $imported);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $imported->id);

    }

    public function testUnsubscribeWithEveryChannel(): void
    {

        $client = $this->client(Fixtures::response('bulk_unsubscribe_profiles.202'));

        $job = new SubscriptionDeleteJob([
            new SubscribeProfile(new SubscriptionChannels(
                email:    new EmailSubscription(new MarketingConsent('UNSUBSCRIBED')),
                sms:      new SmsSubscription(new MarketingConsent('UNSUBSCRIBED'), new MarketingConsent('UNSUBSCRIBED')),
                whatsapp: new WhatsappSubscription(new MarketingConsent('UNSUBSCRIBED')),
                push:     new PushSubscription(new MarketingConsent('UNSUBSCRIBED')),
            ), email: 'jane@example.com', phoneNumber: '+4512345678'),
        ], 'L1');

        self::assertNull($client->profiles->unsubscribe($job));
        self::assertSame('/api/profile-subscription-bulk-delete-jobs', $this->mock->getLastRequest()->getUri()->getPath());
        $body = $this->lastBody();
        $sub = $body['data']['attributes']['profiles']['data'][0]['attributes']['subscriptions'];
        self::assertSame('UNSUBSCRIBED', $sub['email']['marketing']['consent']);
        self::assertSame('UNSUBSCRIBED', $sub['sms']['marketing']['consent']);
        self::assertSame('UNSUBSCRIBED', $sub['sms']['transactional']['consent']);
        self::assertSame('UNSUBSCRIBED', $sub['whatsapp']['marketing']['consent']);
        self::assertSame('UNSUBSCRIBED', $sub['push']['marketing']['consent']);
        self::assertSame('+4512345678', $body['data']['attributes']['profiles']['data'][0]['attributes']['phone_number']);
        self::assertSame(['data' => ['type' => 'list', 'id' => 'L1']], $body['data']['relationships']['list']);

    }

    public function testSuppressionJobListingsAndDeletion(): void
    {

        $client = $this->client(
            Fixtures::response('get_bulk_suppress_profiles_jobs.200'),
            Fixtures::response('get_bulk_suppress_profiles_job.200'),
            Fixtures::response('get_bulk_suppress_profiles_job.404'),
            Fixtures::response('get_bulk_unsuppress_profiles_jobs.200'),
            Fixtures::response('get_bulk_unsuppress_profiles_job.200'),
            Fixtures::response('request_profile_deletion.202'),
            Fixtures::response('request_profile_deletion.404'),
        );

        $jobs = $client->profiles->getSuppressJobs((new Query())->filter(Filter::equals('status', 'complete'))->fields('profile-suppression-bulk-create-job', 'status'));
        self::assertSame('/api/profile-suppression-bulk-create-jobs', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertContainsOnlyInstancesOf(SuppressionCreateJob::class, $jobs['data']);
        self::assertCount(10, $jobs['data']);

        $job = $client->profiles->getSuppressJob('01M1P9S0FX7Z7QMPS8MH8NPJVF');
        self::assertInstanceOf(SuppressionCreateJob::class, $job);
        self::assertSame('01M1P9S0FX7Z7QMPS8MH8NPJVF', $job->id);
        self::assertNotNull($job->status);
        self::assertNull($client->profiles->getSuppressJob('NOPE01'));

        $jobs = $client->profiles->getUnsuppressJobs();
        self::assertSame('/api/profile-suppression-bulk-delete-jobs', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertContainsOnlyInstancesOf(SuppressionDeleteJob::class, $jobs['data']);
        self::assertInstanceOf(SuppressionDeleteJob::class, $client->profiles->getUnsuppressJob($jobs['data'][0]->id));

        self::assertNull($client->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile('01M1PAAR5HBCRHA3NN9NG5WRTA'))));
        self::assertSame('/api/data-privacy-deletion-jobs', $this->mock->getLastRequest()->getUri()->getPath());
        self::assertSame(['type' => 'profile', 'id' => '01M1PAAR5HBCRHA3NN9NG5WRTA'], $this->lastBody()['data']['attributes']['profile']['data']);

        $byEmail = new Profile();
        $byEmail->email = 'nobody@example.com';
        try {
            $client->profiles->deleteProfile(new DataPrivacyDeletionJob($byEmail));
            self::fail('404 expected for an unknown email');
        } catch (ClientException $e) {
            self::assertSame(404, $e->getHttpStatus());
        }

    }

    public function testReturnRequestVariants(): void
    {

        $client = $this->client();
        self::assertSame('POST', $client->profiles->import(new ImportProfile(), returnRequest: true)->getMethod());
        self::assertSame('POST', $client->profiles->unsubscribe(new SubscriptionDeleteJob([]), returnRequest: true)->getMethod());
        self::assertSame('/api/profile-suppression-bulk-create-jobs/j1', $client->profiles->getSuppressJob('j1', returnRequest: true)->getUri()->getPath());
        self::assertSame('/api/data-privacy-deletion-jobs', $client->profiles->deleteProfile(new DataPrivacyDeletionJob(new Profile('p1')), returnRequest: true)->getUri()->getPath());
        self::assertNull($this->mock->getLastRequest());

    }

}
