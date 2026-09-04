<?php


namespace nickdnk\Klaviyo\Tests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Nyholm\Psr7\Response as PsrResponse;
use InvalidArgumentException;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\RetryPolicy;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\MultipartBody;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkImportJob as RequestBulkImportJob;
use nickdnk\Klaviyo\Resources\Request\CreateList;
use nickdnk\Klaviyo\Resources\Request\ImportProfile;
use nickdnk\Klaviyo\Resources\Request\UpdateList;
use nickdnk\Klaviyo\Resources\Response\BulkImportJob;
use nickdnk\Klaviyo\Resources\Response\KlaviyoList;
use nickdnk\Klaviyo\Resources\Response\Profile as ResponseProfile;
use nickdnk\Klaviyo\Resources\Response\SuppressionCreateJob;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\TypeRegistry;
use nickdnk\Klaviyo\Services\BaseService;
use nickdnk\Klaviyo\Services\Traits\HasBulkJobs;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use nickdnk\Klaviyo\Exceptions\ClientException;

/**
 * The plumbing every service is built from, pinned once here rather than per service: Query,
 * the list / get / update / relationship / bulk-job traits, the type registry, multipart.
 */
class ServiceInfrastructureTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

    }

    /** @return array<string, mixed> decoded query string of the last request the mock saw */
    private static function lastQuery(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function json(array $data, ?array $links = null): Response
    {

        $body = ['data' => $data];
        if ($links) {
            $body['links'] = $links;
        }

        return new Response(200, [], json_encode($body));

    }

    // region Query

    public function testQuerySerialisesEveryParameter(): void
    {

        $query = (new Query())
            ->filter('equals(email,"a@b.test")')
            ->include('lists', 'segments')
            ->fields('profile', 'email', 'first_name')
            ->fields('list', 'name')
            ->additionalFields('profile', 'subscriptions')
            ->sort('created', descending: true)
            ->pageSize(50)
            ->cursor('abc');

        self::assertSame([
            'fields[profile]'            => 'email,first_name',
            'fields[list]'               => 'name',
            'additional-fields[profile]' => 'subscriptions',
            'filter'                     => 'equals(email,"a@b.test")',
            'include'                    => 'lists,segments',
            'sort'                       => '-created',
            'page[size]'                 => 50,
            'page[cursor]'               => 'abc',
        ], $query->toArray());

    }

    public function testQueryAccumulatesAndDeduplicatesRepeatedCalls(): void
    {

        $query = (new Query())
            ->include('lists')
            ->include('lists', 'segments')
            ->fields('profile', 'email')
            ->fields('profile', 'email', 'id');

        self::assertSame('lists,segments', $query->toArray()['include']);
        self::assertSame('email,id', $query->toArray()['fields[profile]']);

    }

    public function testEmptyQuerySerialisesToNothing(): void
    {

        self::assertSame([], (new Query())->toArray());
        self::assertSame([], (new Query())->filter('')->cursor('')->toArray());

    }

    public function testSortStripsAnExistingDirectionPrefix(): void
    {

        self::assertSame('created', (new Query())->sort('-created')->toArray()['sort']);
        self::assertSame('-created', (new Query())->sort('-created', descending: true)->toArray()['sort']);

    }

    // endregion

    // region Filter

    public function testFilterQuotesAndEscapesStrings(): void
    {

        self::assertSame('equals(email,"a@b.test")', (string)Filter::equals('email', 'a@b.test'));
        self::assertSame('equals(first_name,"O\\"Brien \\\\ co")', (string)Filter::equals('first_name', 'O"Brien \\ co'));
        self::assertSame('contains(name,"x")', (string)Filter::contains('name', 'x'));
        self::assertSame('starts-with(name,"x")', (string)Filter::startsWith('name', 'x'));
        self::assertSame('ends-with(name,"x")', (string)Filter::endsWith('name', 'x'));

    }

    public function testFilterFormatsScalarsListsAndDates(): void
    {

        self::assertSame('greater-than(value,10)', (string)Filter::greaterThan('value', 10));
        self::assertSame('less-or-equal(value,2.5)', (string)Filter::lessOrEqual('value', 2.5));
        self::assertSame('greater-or-equal(count,0)', (string)Filter::greaterOrEqual('count', 0));
        self::assertSame('equals(archived,false)', (string)Filter::equals('archived', false));
        self::assertSame('any(status,["queued","processing"])', (string)Filter::any('status', ['queued', 'processing']));
        self::assertSame('contains-any(tags,["a","b"])', (string)Filter::containsAny('tags', ['a', 'b']));
        self::assertSame('contains-all(ids,[1,2])', (string)Filter::containsAll('ids', [1, 2]));
        self::assertSame('has(email)', (string)Filter::has('email'));
        self::assertSame(
            'less-than(datetime,2026-01-02T03:04:05+00:00)',
            (string)Filter::lessThan('datetime', new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC')))
        );

    }

    public function testFilterAllJoinsWithComma(): void
    {

        self::assertSame(
            'equals(a,"1"),greater-than(b,2)',
            (string)Filter::all(Filter::equals('a', '1'), Filter::greaterThan('b', 2))
        );

    }

    public function testFilterRejectsBadFieldNames(): void
    {

        self::assertSame('equals(location.city,"x")', (string)Filter::equals('location.city', 'x'));
        self::assertSame('equals($email,"x")', (string)Filter::equals('$email', 'x'));

        $this->expectException(InvalidArgumentException::class);
        Filter::equals('email) or 1=1', 'x');

    }

    public function testQueryAcceptsFilterObject(): void
    {

        self::assertSame(
            ['filter' => 'equals(email,"a@b.test")'],
            (new Query())->filter(Filter::equals('email', 'a@b.test'))->toArray()
        );

    }

    public function testGetByExternalIdEscapesTheValue(): void
    {

        $mock = new MockHandler([self::json([])]);

        self::client($mock)->profiles->getByExternalId('4"2');

        self::assertSame(['filter' => 'equals(external_id,"4\\"2")'], self::lastQuery($mock));

    }

    // endregion

    // region HasCreate / HasUpdate fieldsets

    public function testCreateAndUpdateSendSparseFieldsets(): void
    {

        $mock = new MockHandler([
            self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP']]),
            self::json(['type' => 'list', 'id' => 'L1', 'attributes' => ['name' => 'VIP2']]),
        ]);
        $lists = self::client($mock)->lists;

        $lists->create(new CreateList('VIP'), (new Query())->fields('list', 'name'));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame(['fields' => ['list' => 'name']], self::lastQuery($mock));

        $update = new UpdateList('L1');
        $update->name = 'VIP2';
        $lists->update($update, (new Query())->fields('list', 'name', 'updated'));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame(['fields' => ['list' => 'name,updated']], self::lastQuery($mock));

    }

    public function testCursorAcceptsBareValueOrNextUrl(): void
    {

        self::assertSame(['page[cursor]' => 'abc'], (new Query())->cursor('abc')->toArray());
        self::assertSame(
            ['page[cursor]' => 'WzE2OTBd'],
            (new Query())->cursor('https://a.klaviyo.com/api/profiles?fields%5Bprofile%5D=email&page%5Bcursor%5D=WzE2OTBd&page%5Bsize%5D=20')->toArray(),
            'Only the cursor is taken from a next URL; the other parameters are what this Query already holds.'
        );
        self::assertSame(['page[cursor]' => 'x y'], (new Query())->cursor('https://a.klaviyo.com/api/lists?page[cursor]=x%20y')->toArray());
        self::assertSame([], (new Query())->cursor(null)->toArray());

        $this->expectException(InvalidArgumentException::class);
        (new Query())->cursor('https://a.klaviyo.com/api/lists?page%5Bsize%5D=20');

    }

    // endregion

    // region RetryPolicy

    public function testRetryPolicyStatusesAndJitterBounds(): void
    {

        $lowest = new RetryPolicy(baseDelaySeconds: 2.0, random: fn() => 0.0);
        $highest = new RetryPolicy(baseDelaySeconds: 2.0, random: fn() => 0.999999);
        $none = new RetryPolicy(baseDelaySeconds: 2.0, jitterFactor: 0);

        foreach ([429, 503, 504, 524] as $status) {
            self::assertNotNull($none->delayForResponse(new PsrResponse($status), 1), "$status retries");
        }
        self::assertNull($none->delayForResponse(new PsrResponse(500), 1));
        self::assertNull($none->delayForResponse(new PsrResponse(429), 10), 'attempt 10 of 10 is final');

        self::assertSame(2.0, $none->delayForResponse(new PsrResponse(429), 1), 'attempt 1: base');
        self::assertSame(4.0, $none->delayForResponse(new PsrResponse(429), 2), 'attempt 2: base × 2');
        self::assertSame(8.0, $none->delayForResponse(new PsrResponse(429), 3), 'attempt 3: base × 4 (exponential, per Klaviyo guidance)');
        self::assertSame(60.0, $none->delayForResponse(new PsrResponse(429), 9), 'capped at maxDelaySeconds');
        self::assertSame(5.0, (new RetryPolicy(baseDelaySeconds: 2.0, jitterFactor: 0, maxDelaySeconds: 5.0))->delayForResponse(new PsrResponse(503), 4), 'custom cap');
        self::assertSame(90.0, (new RetryPolicy(baseDelaySeconds: 2.0, jitterFactor: 0, maxDelaySeconds: 5.0))->delayForResponse(new PsrResponse(429, ['Retry-After' => '90']), 4), 'Retry-After is never capped');
        self::assertEqualsWithDelta(2.0, $lowest->delayForResponse(new PsrResponse(429), 2), 0.0001, '-50 % at random 0');
        self::assertEqualsWithDelta(6.0, $highest->delayForResponse(new PsrResponse(429), 2), 0.001, '+50 % at random 1');
        self::assertSame(7.0, $lowest->delayForResponse(new PsrResponse(429, ['Retry-After' => '7']), 2), 'Retry-After is never jittered');
        self::assertSame(0.0, $highest->delayForResponse(new PsrResponse(503, ['Retry-After' => gmdate('D, d M Y H:i:s \\G\\M\\T', time() - 5)]), 1), 'HTTP-date in the past clamps to 0');

    }

    // endregion

    // region HasList / HasGet

    public function testListSendsQueryParameters(): void
    {

        $mock = new MockHandler([self::json([])]);

        self::client($mock)->lists->list(
            (new Query())->filter(Filter::equals('name', 'VIP'))->include('tags')->fields('list', 'name')->pageSize(5)->sort('created')
        );

        $request = $mock->getLastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/lists', $request->getUri()->getPath());
        self::assertSame([
            'fields'  => ['list' => 'name'],
            'filter'  => 'equals(name,"VIP")',
            'include' => 'tags',
            'sort'    => 'created',
            'page'    => ['size' => '5'],
        ], self::lastQuery($mock));

    }

    /** `links.next` already carries the first page's query string; a Query must not be appended twice. */
    public function testListWithNextUrlFollowsItVerbatim(): void
    {

        $mock = new MockHandler([self::json([])]);

        self::client($mock)->lists->list(
            (new Query())->filter('ignored'),
            'https://a.klaviyo.com/api/lists?page%5Bcursor%5D=c2&fields%5Blist%5D=name'
        );

        $uri = $mock->getLastRequest()->getUri();
        self::assertSame('/api/lists', $uri->getPath());
        self::assertSame(['page' => ['cursor' => 'c2'], 'fields' => ['list' => 'name']], self::lastQuery($mock));

    }

    public function testListHydratesDataAndLinks(): void
    {

        $mock = new MockHandler([Fixtures::response('get_lists.200')]);

        $result = self::client($mock)->lists->list();

        self::assertCount(2, $result['data']);
        self::assertInstanceOf(KlaviyoList::class, $result['data'][0]);
        self::assertSame('TjmPN9', $result['data'][0]->id);
        self::assertSame('sdk-smoke-09047c86-list2', $result['data'][0]->name);
        self::assertSame('double_opt_in', $result['data'][0]->opt_in_process);
        // The recording was a filtered single page: `next` is null and `prev` carries the cursor.
        self::assertNull($result['links']->next);
        self::assertStringContainsString('page%5Bcursor%5D=', $result['links']->prev);

    }

    public function testGetSendsSparseFieldsetAndInclude(): void
    {

        $mock = new MockHandler([Fixtures::response('get_profile.200')]);

        $profile = self::client($mock)->profiles->get(
            'p1',
            (new Query())->fields('profile', 'email')->additionalFields('profile', 'subscriptions')->include('lists')
        );

        self::assertSame('/api/profiles/p1', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame([
            'fields'            => ['profile' => 'email'],
            'additional-fields' => ['profile' => 'subscriptions'],
            'include'           => 'lists',
        ], self::lastQuery($mock));
        self::assertInstanceOf(ResponseProfile::class, $profile);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $profile->id);
        self::assertSame('sdk-smoke+09047c86-a@nickdnktech.com', $profile->email);
        self::assertSame('Smoke', $profile->first_name);
        // additional-fields[profile]=subscriptions came back as a nested resource, not a raw array.
        self::assertSame('NEVER_SUBSCRIBED', $profile->subscriptions->email->marketing->consent);
        // `include=lists` matched nothing, so the relationship is present with an empty data member.
        self::assertTrue($profile->getRelationship('lists')->hasData);
        self::assertSame([], $profile->getRelationship('lists')->data);
        self::assertFalse($profile->getRelationship('push-tokens')->hasData);

    }

    /** The status lives in getHttpStatus(), never in getCode(); the 404-to-null contract keys off it. */
    public function testGetReturnsNullOn404(): void
    {

        $mock = new MockHandler([Fixtures::response('get_profile.404')]);

        self::assertNull(self::client($mock)->profiles->get('missing'));

    }

    // endregion

    // region HasUpdate

    public function testUpdatePatchesResourceAtItsOwnId(): void
    {

        $mock = new MockHandler([Fixtures::response('update_list.200')]);

        $list = new UpdateList('L1');
        $list->name = 'Renamed';

        $result = self::client($mock)->lists->update($list);

        $request = $mock->getLastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('/api/lists/L1', $request->getUri()->getPath());
        self::assertSame(
            ['data' => ['type' => 'list', 'attributes' => ['name' => 'Renamed'], 'id' => 'L1']],
            json_decode((string)$request->getBody(), true)
        );
        self::assertInstanceOf(KlaviyoList::class, $result);
        self::assertSame('YafG4m', $result->id);
        self::assertSame('sdk-smoke-09047c86-list-renamed', $result->name);
        self::assertSame('2026-09-04T13:39:31.359707+00:00', $result->updated);

    }

    public function testUpdateReturnsNullOnEmpty204(): void
    {

        $service = self::stubService(fn() => null);

        self::assertNull($service->doUpdate(new UpdateList('L1')));
        self::assertSame(['PATCH', 'things/L1'], array_slice($service->calls[0], 0, 2));

    }

    // endregion

    // region HasRelationships

    public function testRelatedAndRelatedIdsHitTheTwoRelationshipPaths(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_profiles_for_list.200'),
            Fixtures::response('get_profile_ids_for_list.200'),
        ]);
        $client = self::client($mock);

        $full = $client->lists->profiles('L1', (new Query())->pageSize(2));
        self::assertSame('/api/lists/L1/profiles', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame(['page' => ['size' => '2']], self::lastQuery($mock));
        self::assertInstanceOf(ResponseProfile::class, $full['data'][0]);
        self::assertSame('sdk-smoke+09047c86-a@nickdnktech.com', $full['data'][0]->email);
        // Membership listings add joined_group_at, which only exists on this route.
        self::assertSame('2026-09-04T13:39:38+00:00', $full['data'][0]->joined_group_at);

        $ids = $client->lists->profileIds('L1');
        self::assertSame('/api/lists/L1/relationships/profiles', $mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(ResponseProfile::class, $ids['data'][0]);
        self::assertSame('01M1PAAR5HBCRHA3NN9NG5WRTA', $ids['data'][0]->id);
        self::assertNull($ids['data'][0]->email);

    }

    public function testRelationshipMutationsSendIdentifierLists(): void
    {

        $mock = new MockHandler([new Response(204), new Response(204)]);
        $client = self::client($mock);

        $client->lists->addProfiles('L1', ['a' => new Profile('p1'), 'b' => new Profile('p2')]);
        $request = $mock->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/lists/L1/relationships/profiles', $request->getUri()->getPath());
        self::assertSame(
            ['data' => [['type' => 'profile', 'id' => 'p1'], ['type' => 'profile', 'id' => 'p2']]],
            json_decode((string)$request->getBody(), true),
            'Identifier list must be a JSON list even when the caller passed a keyed array.'
        );

        $client->lists->removeProfiles('L1', [new Profile('p1')]);
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame(
            ['data' => [['type' => 'profile', 'id' => 'p1']]],
            json_decode((string)$mock->getLastRequest()->getBody(), true)
        );

    }

    public function testReplaceRelatedUsesPatch(): void
    {

        $service = self::stubService(fn() => null);

        $service->doReplace('T1', 'items', [new Profile('p1')]);

        self::assertSame('PATCH', $service->calls[0][0]);
        self::assertSame('things/T1/relationships/items', $service->calls[0][1]);

    }

    // endregion

    // region BulkJobs

    public function testBulkJobSubmitListAndGet(): void
    {

        $mock = new MockHandler([
            Fixtures::response('bulk_import_profiles.202'),
            Fixtures::response('get_bulk_import_profiles_jobs.200'),
            Fixtures::response('get_bulk_import_profiles_job.200'),
            Fixtures::response('get_bulk_import_profiles_job.404'),
            Fixtures::response('get_bulk_suppress_profiles_job.200'),
        ]);
        $client = self::client($mock);

        $profile = new ImportProfile();
        $profile->email = 'a@b.test';
        $job = $client->profiles->bulkImport(new RequestBulkImportJob([$profile]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/profile-bulk-import-jobs', $mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(BulkImportJob::class, $job);
        self::assertSame('queued', $job->status);
        self::assertSame(3, $job->total_count);
        self::assertSame(['YafG4m'], $job->getRelationship('lists')->ids());

        $jobs = $client->profiles->getBulkImportJobs((new Query())->filter(Filter::any('status', ['queued', 'processing'])));
        self::assertSame('/api/profile-bulk-import-jobs', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame(['filter' => 'any(status,["queued","processing"])'], self::lastQuery($mock));
        self::assertCount(5, $jobs['data']);
        self::assertSame('complete', $jobs['data'][0]->status);

        $one = $client->profiles->getBulkImportJob('j1', (new Query())->include('lists'));
        self::assertSame('/api/profile-bulk-import-jobs/j1', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame(['include' => 'lists'], self::lastQuery($mock));
        self::assertSame('queued', $one->status);
        // `include=lists` was answered under `included` and spliced into the relationship.
        self::assertSame('sdk-smoke-09047c86-list-renamed', $one->getRelationship('lists')->data[0]->name);

        self::assertNull($client->profiles->getBulkImportJob('gone'));

        $suppress = $client->profiles->getSuppressJob('s1');
        self::assertSame('/api/profile-suppression-bulk-create-jobs/s1', $mock->getLastRequest()->getUri()->getPath());
        self::assertInstanceOf(SuppressionCreateJob::class, $suppress);
        self::assertSame('complete', $suppress->status);

    }

    public function testBulkJobHelpersBuildPathsPerFamily(): void
    {

        $service = self::stubService(fn() => ['data' => null, 'links' => null]);
        $query = (new Query())->include('lists');

        $service->jobList('a-jobs', $query);
        $service->jobList('b-jobs', $query, 'https://a.klaviyo.com/api/b-jobs?page%5Bcursor%5D=c2');
        $service->jobGet('a-jobs', 'J1', $query);
        $service->jobRelated('b-jobs', 'J2', 'profiles');
        $service->jobRelatedIds('b-jobs', 'J2', 'profiles', next: 'https://a.klaviyo.com/api/next');

        self::assertSame(
            [
                ['GET', 'a-jobs', ['include' => 'lists']],
                ['GET', 'https://a.klaviyo.com/api/b-jobs?page%5Bcursor%5D=c2', null],
                ['GET', 'a-jobs/J1', ['include' => 'lists']],
                ['GET', 'b-jobs/J2/profiles', null],
                ['GET', 'https://a.klaviyo.com/api/next', null],
            ],
            array_map(static fn(array $call) => [$call[0], $call[1], $call[3]], $service->calls)
        );

    }

    // endregion

    // region TypeRegistry

    public function testTypeRegistryResolvesEveryRegisteredClassByItsOwnType(): void
    {

        $map = TypeRegistry::map();

        self::assertNotEmpty($map);
        foreach ($map as $type => $class) {
            self::assertSame($type, $class::type(), $class);
            self::assertTrue(is_subclass_of($class, IdentifiableResource::class), $class);
            self::assertSame($class, TypeRegistry::resolve($type));
        }

        self::assertSame(ResponseProfile::class, TypeRegistry::resolve('profile'));
        self::assertNull(TypeRegistry::resolve('no-such-type'));

    }

    // endregion

    // region APIClient

    public function testUnknownServiceThrows(): void
    {

        $this->expectException(InvalidArgumentException::class);

        self::client(new MockHandler([]))->nope;

    }

    public function testPinnedRevisionHeaderIsSent(): void
    {

        $mock = new MockHandler([self::json([])]);

        self::client($mock)->accounts->list();

        self::assertSame('2026-07-15', $mock->getLastRequest()->getHeaderLine('revision'));
        self::assertSame('application/vnd.api+json', $mock->getLastRequest()->getHeaderLine('Content-Type'));

    }

    public function testMultipartBodyIsSentAsFormDataWithDefaultHeadersIntact(): void
    {

        $mock = new MockHandler([self::json(['type' => 'image', 'id' => 'i1', 'attributes' => ['name' => 'a.png']])]);
        $client = self::client($mock);

        $makeRequest = (new ReflectionMethod($client, 'makeRequest'))->getClosure($client);
        $body = (new MultipartBody())
            ->withFile('file', 'PNGBYTES', 'a.png', 'image/png')
            ->withField('name', 'Hero');

        $makeRequest('POST', 'image-upload', $body);

        $request = $mock->getLastRequest();
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertSame('Bearer tkn', $request->getHeaderLine('Authorization'));
        self::assertSame('2026-07-15', $request->getHeaderLine('revision'));
        self::assertSame('application/vnd.api+json', $request->getHeaderLine('Accept'));

        $raw = (string)$request->getBody();
        self::assertStringContainsString('name="file"; filename="a.png"', $raw);
        self::assertStringContainsString('Content-Type: image/png', $raw);
        self::assertStringContainsString('PNGBYTES', $raw);
        self::assertStringContainsString('name="name"', $raw);
        self::assertStringContainsString('Hero', $raw);

    }

    public function testMultipartBodyAsPreparedRequestCarriesBoundaryHeader(): void
    {

        $client = self::client(new MockHandler([]));
        $makeRequest = (new ReflectionMethod($client, 'makeRequest'))->getClosure($client);

        $request = $makeRequest('POST', 'image-upload', (new MultipartBody())->withField('a', 'b'), null, true);

        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
        self::assertStringContainsString('name="a"', (string)$request->getBody());

    }

    // endregion

    /**
     * A BaseService over a recording closure, so trait behaviour can be asserted without HTTP.
     *
     * @param Closure(string, string, mixed, ?array, bool): mixed $respond
     */
    private static function stubService(Closure $respond): BaseService
    {

        return new class($respond) extends BaseService {

            use HasBulkJobs;
            use HasRelationships;
            use HasUpdate;

            /** @var list<array{0: string, 1: string, 2: mixed, 3: ?array, 4: bool}> */
            public array $calls = [];

            public function __construct(private readonly Closure $respond)
            {

                parent::__construct(function (string $method, string $endpoint, mixed $body, ?array $query, bool $returnRequest) {
                    $this->calls[] = [$method, $endpoint, $body, $query, $returnRequest];

                    return ($this->respond)($method, $endpoint, $body, $query, $returnRequest);
                });

            }

            public function doUpdate(IdentifiableResource $r): IdentifiableResource|RequestInterface|null
            {

                return $this->updateTrait($r);

            }

            public function doReplace(string $id, string $relation, array $resources): ?RequestInterface
            {

                return $this->replaceRelatedTrait($id, $relation, $resources);

            }

            public function jobList(string $path, ?Query $query = null, ?string $next = null): array|RequestInterface
            {

                return $this->bulkJobList($path, $query, $next);

            }

            public function jobGet(string $path, string $jobId, ?Query $query = null): IdentifiableResource|RequestInterface|null
            {

                return $this->bulkJobGet($path, $jobId, $query);

            }

            public function jobRelated(string $path, string $jobId, string $relation, ?Query $query = null, ?string $next = null): array|RequestInterface
            {

                return $this->bulkJobRelated($path, $jobId, $relation, $query, $next);

            }

            public function jobRelatedIds(string $path, string $jobId, string $relation, ?Query $query = null, ?string $next = null): array|RequestInterface
            {

                return $this->bulkJobRelatedIds($path, $jobId, $relation, $query, $next);

            }

            protected function apiPath(): string
            {

                return 'things';

            }

        };

    }


    /** A 404 is swallowed: deleting something already gone is not an error. */
    public function testDeleteSwallows404ButNotOtherErrors(): void
    {

        $mock = new MockHandler([
            Fixtures::response('delete_list.204'),
            new Response(404, [], json_encode(['errors' => [['detail' => 'not found']]])),
            new Response(403, [], json_encode(['errors' => [['detail' => 'forbidden']]])),
        ]);
        $client = APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

        self::assertNull($client->lists->delete('L1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertNull($client->lists->delete('gone'), '404 on delete is treated as done');

        try {
            $client->lists->delete('forbidden');
            self::fail('403 must propagate');
        } catch (\nickdnk\Klaviyo\Exceptions\ClientException $e) {
            self::assertSame(403, $e->getHttpStatus());
        }

        $request = $client->lists->delete('L2', returnRequest: true);
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/lists/L2', $request->getUri()->getPath());
        self::assertSame(0, $mock->count());

    }

    public function testAccountGet(): void
    {

        $mock = new MockHandler([Fixtures::response('get_account.200'), Fixtures::response('get_account.404')]);
        $client = APIClient::withApiKey('pk', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

        $account = $client->accounts->get('WBhXHN', (new Query())->fields('account', 'timezone'));
        self::assertSame('/api/accounts/WBhXHN', $mock->getLastRequest()->getUri()->getPath());
        self::assertSame('fields%5Baccount%5D=timezone', $mock->getLastRequest()->getUri()->getQuery());
        self::assertInstanceOf(\nickdnk\Klaviyo\Resources\Response\Account::class, $account);
        self::assertSame('WBhXHN', $account->id);
        self::assertNotNull($account->timezone);

        self::assertNull($client->accounts->get('NOPE01'));

    }

    public function testFilterAllNeedsAtLeastOneFilter(): void
    {

        $this->expectException(InvalidArgumentException::class);
        Filter::all();

    }


    public function testNon404ErrorsPropagateFromOneLookups(): void
    {

        $client = self::client(new MockHandler(array_fill(0, 3, new Response(403, [], json_encode(['errors' => [['detail' => 'nope']]])))));

        foreach ([
            fn() => $client->campaigns->getRecipientEstimation('c1'),
            fn() => $client->profiles->getBulkImportJob('j1'),
            fn() => $client->events->profile('e1'),
        ] as $call) {
            try {
                $call();
                self::fail('403 must propagate');
            } catch (ClientException $e) {
                self::assertSame(403, $e->getHttpStatus());
            }
        }

    }


    public function testToOneRelationLookupsHonourReturnRequest(): void
    {

        $mock = new MockHandler();
        $request = self::client($mock)->events->profile('e1', returnRequest: true);
        self::assertSame('/api/events/e1/profile', $request->getUri()->getPath());
        self::assertNull($mock->getLastRequest(), 'returnRequest sends nothing');

    }


    public function testBulkJobSubmitReturnsThePreparedRequest(): void
    {

        $mock = new MockHandler();
        $request = self::client($mock)->profiles->bulkImport(new RequestBulkImportJob([]), returnRequest: true);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/profile-bulk-import-jobs', $request->getUri()->getPath());
        self::assertNull($mock->getLastRequest(), 'returnRequest sends nothing');

    }

}
