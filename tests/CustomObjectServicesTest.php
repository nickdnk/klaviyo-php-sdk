<?php


namespace nickdnk\Klaviyo\Tests;

use DateTimeImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
use nickdnk\Klaviyo\Http\GuzzleTransport;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateDataSourceRecordsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteObjectRecordsJob;
use nickdnk\Klaviyo\Resources\Request\CreateDataSource;
use nickdnk\Klaviyo\Resources\Request\CreateDataSourceRecordJob;
use nickdnk\Klaviyo\Resources\Request\CreateObjectSchema;
use nickdnk\Klaviyo\Resources\Request\CreateObjectType;
use nickdnk\Klaviyo\Resources\Request\ObjectSchemaRelationship;
use nickdnk\Klaviyo\Resources\Request\ProfileObjectSchemaRelationship;
use nickdnk\Klaviyo\Resources\Request\UpdateObjectSchema;
use nickdnk\Klaviyo\Resources\Request\UpdateSourceMapping;
use nickdnk\Klaviyo\Resources\Response\DataSource;
use nickdnk\Klaviyo\Resources\Response\ObjectIngestionLog;
use nickdnk\Klaviyo\Resources\Response\ObjectRecord;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema;
use nickdnk\Klaviyo\Resources\Response\ObjectType;
use nickdnk\Klaviyo\Resources\Response\SourceMapping;
use nickdnk\Klaviyo\Resources\Shared\ObjectSchema as SharedObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\ProfileObjectType;
use nickdnk\Klaviyo\Resources\Shared\SourceMapping as SharedSourceMapping;
use PHPUnit\Framework\TestCase;

/**
 * Only the data-source endpoints recorded successfully against the live account; every
 * object-type / object-schema / object-record / source-mapping fixture is a 403/404/500 or an
 * empty collection, so those responses are hand-written.
 */
class CustomObjectServicesTest extends TestCase
{

    private static function client(MockHandler $mock): APIClient
    {

        return APIClient::withAccessToken('tkn', GuzzleTransport::fromHandlerStack(HandlerStack::create($mock)));

    }

    private static function json(mixed $data, int $status = 200): Response
    {

        return new Response($status, [], json_encode(['data' => $data]));

    }

    private static function path(MockHandler $mock): string
    {

        return $mock->getLastRequest()->getUri()->getPath();

    }

    private static function query(MockHandler $mock): array
    {

        parse_str($mock->getLastRequest()->getUri()->getQuery(), $out);

        return $out;

    }

    private static function body(MockHandler $mock): array
    {

        return json_decode((string)$mock->getLastRequest()->getBody(), true);

    }

    // region Data sources

    public function testDataSourceListGetCreateDelete(): void
    {

        $mock = new MockHandler([
            Fixtures::response('get_data_sources.200'),
            Fixtures::response('get_data_source.200'),
            Fixtures::response('create_data_source.201'),
            new Response(204),
        ]);
        $dataSources = self::client($mock)->dataSources;

        $list = $dataSources->list((new Query())->fields('data-source', 'title')->pageSize(50)->cursor('cur1'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/data-sources', self::path($mock));
        self::assertSame([
            'fields' => ['data-source' => 'title'],
            'page'   => ['size' => '50', 'cursor' => 'cur1'],
        ], self::query($mock));
        self::assertCount(1, $list['data'], 'the recording used page[size]=1');
        self::assertInstanceOf(DataSource::class, $list['data'][0]);
        self::assertSame('01M1PACTCRSM6Z1S7ECC3D5M9H', $list['data'][0]->id, 'data sources get ULIDs');
        self::assertSame('sdk-smoke-090443cf-ds2', $list['data'][0]->title);
        self::assertSame('private', $list['data'][0]->visibility);
        self::assertSame('custom-objects', $list['data'][0]->namespace, 'the account, not the caller, picks the namespace');
        self::assertSame([], $list['data'][0]->getRelationships(), 'a data source carries no relationships');
        self::assertNull($list['links']->next);

        $dataSource = $dataSources->get('ds1', (new Query())->fields('data-source', 'title', 'namespace'));
        self::assertSame('/api/data-sources/ds1', self::path($mock));
        self::assertSame(['fields' => ['data-source' => 'title,namespace']], self::query($mock));
        self::assertInstanceOf(DataSource::class, $dataSource);
        self::assertSame('sdk-smoke-090443cf-ds2', $dataSource->title);
        self::assertNull($dataSource->namespace, 'the recording asked for fields[data-source]=title only');

        $created = $dataSources->create(
            new CreateDataSource('Orders feed', 'shared', 'Nightly order dump', 'acme'),
            (new Query())->fields('data-source', 'title')
        );
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/data-sources', self::path($mock));
        self::assertSame(['fields' => ['data-source' => 'title']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'data-source',
                'attributes' => [
                    'title'       => 'Orders feed',
                    'visibility'  => 'shared',
                    'description' => 'Nightly order dump',
                    'namespace'   => 'acme',
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(DataSource::class, $created);
        self::assertSame('01M1PACTCRSM6Z1S7ECC3D5M9H', $created->id);
        self::assertSame('private', $created->visibility, 'the recording created a private source');
        self::assertSame('', $created->description, 'an omitted description comes back as an empty string, not null');

        self::assertNull($dataSources->delete('ds1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/data-sources/ds1', self::path($mock));

    }

    public function testDataSourceRecordIngestionJobs(): void
    {

        $mock = new MockHandler([
            new Response(204),
            new Response(204),
        ]);
        $dataSources = self::client($mock)->dataSources;

        self::assertNull($dataSources->createRecord(new CreateDataSourceRecordJob(['order_id' => 'o1', 'total' => 42.5], 'ds1')));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/data-source-record-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'data-source-record-create-job',
                'attributes' => [
                    'data-source-record' => [
                        'data' => [
                            'type'       => 'data-source-record',
                            'attributes' => ['record' => ['order_id' => 'o1', 'total' => 42.5]],
                        ],
                    ],
                ],
                'relationships' => [
                    'data-source' => ['data' => ['type' => 'data-source', 'id' => 'ds1']],
                ],
            ],
        ], self::body($mock));

        self::assertNull($dataSources->bulkCreateRecords(
            new BulkCreateDataSourceRecordsJob([['order_id' => 'o1'], ['order_id' => 'o2']], 'ds1')
        ));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/data-source-record-bulk-create-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'data-source-record-bulk-create-job',
                'attributes' => [
                    'data-source-records' => [
                        'data' => [
                            ['type' => 'data-source-record', 'attributes' => ['record' => ['order_id' => 'o1']]],
                            ['type' => 'data-source-record', 'attributes' => ['record' => ['order_id' => 'o2']]],
                        ],
                    ],
                ],
                'relationships' => [
                    'data-source' => ['data' => ['type' => 'data-source', 'id' => 'ds1']],
                ],
            ],
        ], self::body($mock));

    }

    // endregion

    // region Object types

    public function testObjectTypeListGetCreateDelete(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'object-type', 'id' => 'ot1', 'attributes' => ['title' => 'Order', 'status' => 'active']]]),
            self::json(['type' => 'object-type', 'id' => 'ot1', 'attributes' => ['title' => 'Order', 'namespace' => 'acme']]),
            self::json(['type' => 'object-type', 'id' => 'ot9', 'attributes' => ['title' => 'Order', 'status' => 'draft']], 201),
            new Response(204),
        ]);
        $objectTypes = self::client($mock)->objectTypes;

        $list = $objectTypes->list((new Query())->filter(Filter::equals('namespace', 'acme'))->include('current-schema'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types', self::path($mock));
        self::assertSame([
            'filter'  => 'equals(namespace,"acme")',
            'include' => 'current-schema',
        ], self::query($mock));
        self::assertInstanceOf(ObjectType::class, $list['data'][0]);
        self::assertSame('active', $list['data'][0]->status);

        $objectType = $objectTypes->get('ot1', (new Query())->fields('object-type', 'title')->include('draft-schema'));
        self::assertSame('/api/object-types/ot1', self::path($mock));
        self::assertSame([
            'fields'  => ['object-type' => 'title'],
            'include' => 'draft-schema',
        ], self::query($mock));
        self::assertInstanceOf(ObjectType::class, $objectType);
        self::assertSame('acme', $objectType->namespace);

        $schema = new SharedObjectSchema();
        $schema->properties = [['id' => 1, 'name' => 'total', 'type' => 'float']];
        $schema->status = 'draft';
        $schema->required = ['total'];

        $created = $objectTypes->create(new CreateObjectType('Order', 'Purchases', 'private', 'acme', $schema));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'object-type',
                'attributes' => [
                    'title'         => 'Order',
                    'description'   => 'Purchases',
                    'visibility'    => 'private',
                    'namespace'     => 'acme',
                    'object-schema' => [
                        'data' => [
                            'type'       => 'object-schema',
                            'attributes' => [
                                'properties' => [['id' => 1, 'name' => 'total', 'type' => 'float']],
                                'status'     => 'draft',
                                'required'   => ['total'],
                            ],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(ObjectType::class, $created);
        self::assertSame('ot9', $created->id);

        self::assertNull($objectTypes->delete('ot1'));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types/ot1', self::path($mock));

    }

    public function testObjectTypeSchemaRelationships(): void
    {

        $mock = new MockHandler([
            self::json(['type' => 'object-schema', 'id' => 'os1', 'attributes' => ['title' => 'Order v1', 'status' => 'active']]),
            self::json(['type' => 'object-schema', 'id' => 'os1']),
            self::json(['type' => 'object-schema', 'id' => 'os2', 'attributes' => ['title' => 'Order v2', 'status' => 'draft']]),
            self::json(['type' => 'object-schema', 'id' => 'os2']),
            self::json([
                ['type' => 'object-schema', 'id' => 'os1', 'attributes' => ['title' => 'Order v1', 'published_at' => '2026-01-01T00:00:00Z']],
                ['type' => 'object-schema', 'id' => 'os2', 'attributes' => ['title' => 'Order v2']],
            ]),
            self::json([['type' => 'object-schema', 'id' => 'os1'], ['type' => 'object-schema', 'id' => 'os2']]),
        ]);
        $objectTypes = self::client($mock)->objectTypes;

        $current = $objectTypes->currentSchema('ot1', (new Query())->fields('object-schema', 'title', 'status'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types/ot1/current-schema', self::path($mock));
        self::assertSame(['fields' => ['object-schema' => 'title,status']], self::query($mock));
        self::assertInstanceOf(ObjectSchema::class, $current);
        self::assertSame('Order v1', $current->title);

        self::assertSame('os1', $objectTypes->currentSchemaId('ot1')->id);
        self::assertSame('/api/object-types/ot1/relationships/current-schema', self::path($mock));

        $draft = $objectTypes->draftSchema('ot1');
        self::assertSame('/api/object-types/ot1/draft-schema', self::path($mock));
        self::assertInstanceOf(ObjectSchema::class, $draft);
        self::assertSame('draft', $draft->status);

        self::assertSame('os2', $objectTypes->draftSchemaId('ot1')->id);
        self::assertSame('/api/object-types/ot1/relationships/draft-schema', self::path($mock));

        $versions = $objectTypes->schemaVersions('ot1', (new Query())->fields('object-schema', 'title'));
        self::assertSame('/api/object-types/ot1/schema-versions', self::path($mock));
        self::assertSame(['fields' => ['object-schema' => 'title']], self::query($mock));
        self::assertCount(2, $versions['data']);
        self::assertInstanceOf(ObjectSchema::class, $versions['data'][0]);
        self::assertSame('2026-01-01T00:00:00Z', $versions['data'][0]->published_at);

        $versionIds = $objectTypes->schemaVersionIds('ot1');
        self::assertSame('/api/object-types/ot1/relationships/schema-versions', self::path($mock));
        self::assertSame(['os1', 'os2'], array_map(fn($schema) => $schema->id, $versionIds['data']));

    }

    public function testObjectTypeRecordsAndIngestionLogs(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'object-record', 'id' => 'ot1:r1', 'attributes' => ['record_properties' => ['total' => 42.5]]]]),
            self::json([['type' => 'object-record', 'id' => 'ot1:r1'], ['type' => 'object-record', 'id' => 'ot1:r2']]),
            self::json([[
                'type'       => 'object-ingestion-log',
                'id'         => 'log1',
                'attributes' => ['status' => 'error', 'event_type' => 'validation_error', 'summary' => 'total is not a float'],
            ]]),
            self::json([['type' => 'object-ingestion-log', 'id' => 'log1']]),
        ]);
        $objectTypes = self::client($mock)->objectTypes;

        $records = $objectTypes->records('ot1', (new Query())->fields('object-record', 'record_properties')->pageSize(20));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types/ot1/object-records', self::path($mock));
        self::assertSame([
            'fields' => ['object-record' => 'record_properties'],
            'page'   => ['size' => '20'],
        ], self::query($mock));
        self::assertInstanceOf(ObjectRecord::class, $records['data'][0]);
        self::assertSame(['total' => 42.5], $records['data'][0]->record_properties);

        $recordIds = $objectTypes->recordIds('ot1', (new Query())->cursor('cur2'));
        self::assertSame('/api/object-types/ot1/relationships/object-records', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'cur2']], self::query($mock));
        self::assertSame(['ot1:r1', 'ot1:r2'], array_map(fn($record) => $record->id, $recordIds['data']));

        $logs = $objectTypes->ingestionLogs('ot1', (new Query())->filter(Filter::greaterThan('timestamp', new DateTimeImmutable('2026-01-01T00:00:00Z')))->include('object-record'));
        self::assertSame('/api/object-types/ot1/object-ingestion-logs', self::path($mock));
        self::assertSame([
            'filter'  => 'greater-than(timestamp,2026-01-01T00:00:00+00:00)',
            'include' => 'object-record',
        ], self::query($mock));
        self::assertInstanceOf(ObjectIngestionLog::class, $logs['data'][0]);
        self::assertSame('validation_error', $logs['data'][0]->event_type);

        $logIds = $objectTypes->ingestionLogIds('ot1', (new Query())->cursor('cur3'));
        self::assertSame('/api/object-types/ot1/relationships/object-ingestion-logs', self::path($mock));
        self::assertSame(['page' => ['cursor' => 'cur3']], self::query($mock));
        self::assertSame('log1', $logIds['data'][0]->id);

    }

    public function testObjectTypeLinkageRelationships(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'object-type', 'id' => 'ot2'], ['type' => 'object-type', 'id' => 'ot3']]),
            self::json([['type' => 'profile-object-type', 'id' => 'pot1']]),
        ]);
        $objectTypes = self::client($mock)->objectTypes;

        $related = $objectTypes->objectTypeRelationships('ot1');
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-types/ot1/relationships/object-types', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertInstanceOf(ObjectType::class, $related['data'][0]);
        self::assertSame(['ot2', 'ot3'], array_map(fn($type) => $type->id, $related['data']));

        $profileTypes = $objectTypes->profileTypeRelationships('ot1');
        self::assertSame('/api/object-types/ot1/relationships/profile-object-types', self::path($mock));
        self::assertInstanceOf(ProfileObjectType::class, $profileTypes['data'][0]);
        self::assertSame('pot1', $profileTypes['data'][0]->id);

    }

    // endregion

    // region Object schemas

    public function testObjectSchemaGetCreateUpdate(): void
    {

        $mock = new MockHandler([
            self::json([
                'type'       => 'object-schema',
                'id'         => 'os1',
                'attributes' => [
                    'title'      => 'Order v1',
                    'status'     => 'active',
                    'properties' => [['id' => 1, 'name' => 'total', 'type' => 'float']],
                    'required'   => ['total'],
                ],
            ]),
            self::json(['type' => 'object-schema', 'id' => 'os9', 'attributes' => ['title' => 'Order v2', 'status' => 'draft']], 201),
            self::json(['type' => 'object-schema', 'id' => 'os9', 'attributes' => ['title' => 'Order v3', 'status' => 'active']]),
        ]);
        $objectSchemas = self::client($mock)->objectSchemas;

        $schema = $objectSchemas->get('os1', (new Query())->fields('object-schema', 'title')->include('source-mapping'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1', self::path($mock));
        self::assertSame([
            'fields'  => ['object-schema' => 'title'],
            'include' => 'source-mapping',
        ], self::query($mock));
        self::assertInstanceOf(ObjectSchema::class, $schema);
        self::assertSame([['id' => 1, 'name' => 'total', 'type' => 'float']], $schema->properties);

        $sourceMapping = new SharedSourceMapping();
        $sourceMapping->property_mappings = [['id' => 1, 'type' => 'constant', 'value' => 5]];

        $created = $objectSchemas->create(new CreateObjectSchema(
            'Order v2',
            [['id' => 1, 'name' => 'total', 'type' => 'float', 'description' => null]],
            'Second cut',
            'draft',
            ['total'],
            $sourceMapping,
            'ot1'
        ));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'object-schema',
                'attributes' => [
                    'title'          => 'Order v2',
                    'properties'     => [['id' => 1, 'name' => 'total', 'type' => 'float']],
                    'description'    => 'Second cut',
                    'status'         => 'draft',
                    'required'       => ['total'],
                    'source-mapping' => [
                        'data' => [
                            'type'       => 'source-mapping',
                            'attributes' => ['property_mappings' => [['id' => 1, 'type' => 'constant', 'value' => 5]]],
                        ],
                    ],
                ],
                'relationships' => [
                    'object-type' => ['data' => ['type' => 'object-type', 'id' => 'ot1']],
                ],
            ],
        ], self::body($mock));
        self::assertInstanceOf(ObjectSchema::class, $created);
        self::assertSame('os9', $created->id);

        $update = new UpdateObjectSchema('os9');
        $update->title = 'Order v3';
        $update->status = 'active';
        $updated = $objectSchemas->update($update, (new Query())->fields('object-schema', 'status'));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os9', self::path($mock));
        self::assertSame(['fields' => ['object-schema' => 'status']], self::query($mock));
        self::assertSame([
            'data' => [
                'type'       => 'object-schema',
                'attributes' => ['title' => 'Order v3', 'status' => 'active'],
                'id'         => 'os9',
            ],
        ], self::body($mock));
        self::assertInstanceOf(ObjectSchema::class, $updated);
        self::assertSame('active', $updated->status);

    }

    public function testObjectSchemaSourceMapping(): void
    {

        $mock = new MockHandler([
            self::json([
                'type'       => 'source-mapping',
                'id'         => 'sm1',
                'attributes' => [
                    'property_mappings'     => [['id' => 1, 'type' => 'constant', 'value' => 5]],
                    'relationship_mappings' => [],
                ],
            ]),
            self::json(['type' => 'source-mapping', 'id' => 'sm1']),
        ]);
        $objectSchemas = self::client($mock)->objectSchemas;

        $mapping = $objectSchemas->sourceMapping('os1', (new Query())->fields('source-mapping', 'property_mappings'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/source-mapping', self::path($mock));
        self::assertSame(['fields' => ['source-mapping' => 'property_mappings']], self::query($mock));
        self::assertInstanceOf(SourceMapping::class, $mapping);
        self::assertSame([['id' => 1, 'type' => 'constant', 'value' => 5]], $mapping->property_mappings);

        $mappingId = $objectSchemas->sourceMappingId('os1');
        self::assertSame('/api/object-schemas/os1/relationships/source-mapping', self::path($mock));
        self::assertSame([], self::query($mock));
        self::assertInstanceOf(SourceMapping::class, $mappingId);
        self::assertSame('sm1', $mappingId->id);

    }

    public function testObjectSchemaRelationshipQuartet(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'object-schema', 'id' => 'os2'], ['type' => 'object-schema', 'id' => 'os3']]),
            new Response(204),
            new Response(204),
            new Response(204),
        ]);
        $objectSchemas = self::client($mock)->objectSchemas;

        $linked = $objectSchemas->objectSchemaRelationships('os1');
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/object-schemas', self::path($mock));
        self::assertInstanceOf(ObjectSchema::class, $linked['data'][0]);
        self::assertSame(['os2', 'os3'], array_map(fn($schema) => $schema->id, $linked['data']));

        self::assertNull($objectSchemas->createObjectSchemaRelationship('os1', [
            new ObjectSchemaRelationship('os2', name: 'line_items', description: 'Order lines'),
        ]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                [
                    'type' => 'object-schema',
                    'id'   => 'os2',
                    'meta' => ['name' => 'line_items', 'description' => 'Order lines'],
                ],
            ],
        ], self::body($mock));

        self::assertNull($objectSchemas->updateObjectSchemaRelationship(
            'os1',
            new ObjectSchemaRelationship('os2', 'rel1', 'items', 'Renamed')
        ));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                'type' => 'object-schema',
                'id'   => 'os2',
                'meta' => ['relationship_id' => 'rel1', 'name' => 'items', 'description' => 'Renamed'],
            ],
        ], self::body($mock));

        self::assertNull($objectSchemas->deleteObjectSchemaRelationship('os1', [
            new ObjectSchemaRelationship('os2', 'rel1'),
        ]));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                ['type' => 'object-schema', 'id' => 'os2', 'meta' => ['relationship_id' => 'rel1']],
            ],
        ], self::body($mock));

    }

    public function testProfileSchemaRelationshipQuartet(): void
    {

        $mock = new MockHandler([
            self::json([['type' => 'profile-object-schema', 'id' => 'pos1']]),
            new Response(204),
            new Response(204),
            new Response(204),
        ]);
        $objectSchemas = self::client($mock)->objectSchemas;

        $linked = $objectSchemas->profileSchemaRelationships('os1');
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/profile-object-schemas', self::path($mock));
        self::assertInstanceOf(ProfileObjectSchema::class, $linked['data'][0]);
        self::assertSame('pos1', $linked['data'][0]->id);

        self::assertNull($objectSchemas->createProfileSchemaRelationship('os1', [
            new ProfileObjectSchemaRelationship('pos1', name: 'buyer'),
        ]));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/profile-object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                ['type' => 'profile-object-schema', 'id' => 'pos1', 'meta' => ['name' => 'buyer']],
            ],
        ], self::body($mock));

        self::assertNull($objectSchemas->updateProfileSchemaRelationship(
            'os1',
            new ProfileObjectSchemaRelationship('pos1', 'rel9', 'purchaser')
        ));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/profile-object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                'type' => 'profile-object-schema',
                'id'   => 'pos1',
                'meta' => ['relationship_id' => 'rel9', 'name' => 'purchaser'],
            ],
        ], self::body($mock));

        self::assertNull($objectSchemas->deleteProfileSchemaRelationship('os1', [
            new ProfileObjectSchemaRelationship('pos1', 'rel9'),
        ]));
        self::assertSame('DELETE', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-schemas/os1/relationships/profile-object-schemas', self::path($mock));
        self::assertSame([
            'data' => [
                ['type' => 'profile-object-schema', 'id' => 'pos1', 'meta' => ['relationship_id' => 'rel9']],
            ],
        ], self::body($mock));

    }

    // endregion

    // region Object records

    public function testObjectRecordGetAndBulkDelete(): void
    {

        $mock = new MockHandler([
            self::json([
                'type'       => 'object-record',
                'id'         => 'ot1:r1',
                'attributes' => ['record_properties' => ['total' => 42.5, 'currency' => 'EUR']],
            ]),
            new Response(202),
        ]);
        $objectRecords = self::client($mock)->objectRecords;

        $record = $objectRecords->get('ot1:r1', (new Query())->fields('object-record', 'record_properties'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-records/ot1:r1', self::path($mock));
        self::assertSame(['fields' => ['object-record' => 'record_properties']], self::query($mock));
        self::assertInstanceOf(ObjectRecord::class, $record);
        self::assertSame(['total' => 42.5, 'currency' => 'EUR'], $record->record_properties);

        self::assertNull($objectRecords->bulkDelete(new BulkDeleteObjectRecordsJob(['ot1:r1', 'ot1:r2'])));
        self::assertSame('POST', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/object-record-bulk-delete-jobs', self::path($mock));
        self::assertSame([
            'data' => [
                'type'          => 'object-record-bulk-delete-job',
                'relationships' => [
                    'object-records' => [
                        'data' => [
                            ['type' => 'object-record', 'id' => 'ot1:r1'],
                            ['type' => 'object-record', 'id' => 'ot1:r2'],
                        ],
                    ],
                ],
            ],
        ], self::body($mock));

    }

    // endregion

    // region Source mappings

    public function testSourceMappingGetAndUpdate(): void
    {

        $mock = new MockHandler([
            self::json([
                'type'       => 'source-mapping',
                'id'         => 'sm1',
                'attributes' => [
                    'property_mappings'     => [['id' => 1, 'type' => 'constant', 'value' => 5]],
                    'relationship_mappings' => [],
                ],
            ]),
            self::json([
                'type'       => 'source-mapping',
                'id'         => 'sm1',
                'attributes' => [
                    'property_mappings'     => [['id' => 1, 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'id_path' => '$.id', 'data_path' => '$.total']]],
                    'relationship_mappings' => [['relationship_id' => 'rel1', 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'type' => 'object', 'id_path' => '$.id', 'related_id_path' => '$.buyer']]],
                ],
            ]),
        ]);
        $sourceMappings = self::client($mock)->sourceMappings;

        $mapping = $sourceMappings->get('sm1', (new Query())->fields('source-mapping', 'property_mappings'));
        self::assertSame('GET', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/source-mappings/sm1', self::path($mock));
        self::assertSame(['fields' => ['source-mapping' => 'property_mappings']], self::query($mock));
        self::assertInstanceOf(SourceMapping::class, $mapping);
        self::assertSame([['id' => 1, 'type' => 'constant', 'value' => 5]], $mapping->property_mappings);

        $updated = $sourceMappings->update(new UpdateSourceMapping(
            'sm1',
            [['id' => 1, 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'id_path' => '$.id', 'data_path' => '$.total']]],
            [['relationship_id' => 'rel1', 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'type' => 'object', 'id_path' => '$.id', 'related_id_path' => '$.buyer']]]
        ));
        self::assertSame('PATCH', $mock->getLastRequest()->getMethod());
        self::assertSame('/api/source-mappings/sm1', self::path($mock));
        self::assertSame([
            'data' => [
                'type'       => 'source-mapping',
                'attributes' => [
                    'property_mappings'     => [['id' => 1, 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'id_path' => '$.id', 'data_path' => '$.total']]],
                    'relationship_mappings' => [['relationship_id' => 'rel1', 'type' => 'simple', 'source' => ['source_id' => 'ds1', 'type' => 'object', 'id_path' => '$.id', 'related_id_path' => '$.buyer']]],
                ],
                'id'         => 'sm1',
            ],
        ], self::body($mock));
        self::assertInstanceOf(SourceMapping::class, $updated);
        self::assertSame('simple', $updated->property_mappings[0]['type']);

    }

    // endregion

}
