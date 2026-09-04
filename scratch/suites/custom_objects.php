<?php
/**
 * Custom Objects, end to end: DataSourceService + ObjectTypeService + ObjectSchemaService +
 * ObjectRecordService + SourceMappingService.
 *
 * Data sources work on this account; `POST /api/object-types` answers
 * 403 "Entitlement to create object type not found.", so everything hanging off an object
 * type runs against a syntactically valid but non-existent ULID instead. That keeps every
 * SDK method exercised (and documents the 404 / null-on-unknown-id path) while the flow
 * below stays the real one for an account where the entitlement is on.
 */
declare(strict_types=1);

use nickdnk\Klaviyo\APIClient;
use nickdnk\Klaviyo\Filter;
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
use nickdnk\Klaviyo\Resources\Response\ObjectRecord;
use nickdnk\Klaviyo\Resources\Response\ObjectSchema as ObjectSchemaResponse;
use nickdnk\Klaviyo\Resources\Response\ObjectType;
use nickdnk\Klaviyo\Resources\Response\SourceMapping as SourceMappingResponse;
use nickdnk\Klaviyo\Resources\Shared\ObjectSchema;
use nickdnk\Klaviyo\Resources\Shared\SourceMapping;
use Smoke\Harness;

return function (Harness $h): void {

    // Non-existent but well-formed ULIDs / compound ids, used as stand-ins when the account
    // cannot create object types.
    $absentType   = '01K61DKJ7EKJ0ES9VE456XF5JG';
    $absentType2  = '01K61DKJ7EKJ0ES9VE456XF5JH';
    $absentSchema = '01K61DKJ7EKJ0ES9VE456XF5JJ';
    $absentSchema2 = '01K61DKJ7EKJ0ES9VE456XF5JK';
    $absentMapping = '01K61DKJ7EKJ0ES9VE456XF5JM';
    $absentObjectRelId  = '01K61DKJ7EKJ0ES9VE456XF5JN';
    $absentProfileRelId = '01K61DKJ7EKJ0ES9VE456XF5JP';
    $absentRecord = $absentType . ':::sdk-smoke-missing';

    // Reads the raw JSON:API body for a path the SDK exposes only through hydration.
    // Needed for object-schema linkage `relationship_id`s, which live in `meta` and are
    // dropped on hydration (see the note at the bottom of this suite).
    $rawGet = function (string $path) use ($h): ?array {
        $ch = curl_init('https://a.klaviyo.com/api/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Klaviyo-API-Key ' . $h->env['KLAVIYO_API_KEY'],
                'Accept: application/vnd.api+json',
                'revision: 2026-07-15',
            ],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? json_decode($body, true) : null;
    };

    // ───────────────────────────── Data sources ─────────────────────────────

    /** @var DataSource|null $ds */
    $ds = $h->step('dataSources', 'create', 'create data source (title, visibility, description, namespace) + fields[data-source]',
        fn(APIClient $c) => $c->dataSources->create(
            new CreateDataSource($h->name('ds'), 'private', 'Smoke-test upstream feed', 'custom-objects'),
            (new Query())->fields('data-source', 'title', 'visibility', 'description', 'namespace')
        ),
        fn($r) => $h->assert(
            $r instanceof DataSource && $r->id
            && $r->title === $h->name('ds')
            && $r->visibility === 'private'
            && $r->description === 'Smoke-test upstream feed'
            && $r->namespace === 'custom-objects',
            'hydrated data source with all four attributes echoed'
        ));
    if ($ds) {
        $h->cleanup('dataSources', 'delete', 'data source', fn(APIClient $c) => $c->dataSources->delete($ds->id));
    }

    /** @var DataSource|null $ds2 */
    $ds2 = $h->step('dataSources', 'create', 'create data source (title only → server defaults)',
        fn(APIClient $c) => $c->dataSources->create(new CreateDataSource($h->name('ds2'))),
        fn($r) => $h->assert(
            $r instanceof DataSource && $r->visibility === 'private' && $r->namespace === 'custom-objects' && $r->description === '',
            'defaults applied: private / custom-objects / empty description'
        ));
    if ($ds2) {
        $h->cleanup('dataSources', 'delete', 'data source 2', fn(APIClient $c) => $c->dataSources->delete($ds2->id));
    }

    if (!$ds) {
        $h->note('Cannot continue without a data source.');

        return;
    }

    $h->step('dataSources', 'get', 'get data source w/ fields[data-source]',
        fn(APIClient $c) => $c->dataSources->get($ds->id, (new Query())->fields('data-source', 'title', 'namespace')),
        fn($r) => $h->assert($r instanceof DataSource && $r->id === $ds->id && $r->title === $h->name('ds'), 'same data source back'));

    $h->step('dataSources', 'get', 'get unknown data source → null',
        fn(APIClient $c) => $c->dataSources->get($absentType),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('dataSources', 'list', 'list data sources w/ fields + page[size]=100',
        fn(APIClient $c) => $c->dataSources->list((new Query())
            ->fields('data-source', 'title', 'visibility', 'description', 'namespace')
            ->pageSize(100)),
        function ($r) use ($h, $ds, $ds2) {
            $ids = array_map(fn($d) => $d->id, $r['data']);
            $h->assert(in_array($ds->id, $ids, true), 'our data source is listed');
            if ($ds2) {
                $h->assert(in_array($ds2->id, $ids, true), 'second data source is listed');
            }
            $h->assert($r['data'][0] instanceof DataSource, 'entries hydrate to DataSource');
        });

    $h->step('dataSources', 'list', 'paginate data sources via links.next (page[size]=1) + Query::cursor',
        function (APIClient $c) use ($h) {
            $p1 = $c->dataSources->list((new Query())->pageSize(1));
            $h->assert(count($p1['data']) === 1, 'first page has 1');
            $h->assert($p1['links']?->next !== null, 'has next link');
            $p2 = $c->dataSources->list(next: $p1['links']->next);
            $h->assert(count($p2['data']) === 1 && $p2['data'][0]->id !== $p1['data'][0]->id, 'second page differs');
            $viaCursor = $c->dataSources->list((new Query())->pageSize(1)->cursor($p1['links']->next));
            $h->assert($viaCursor['data'][0]->id === $p2['data'][0]->id, 'Query::cursor(url) equals links.next');

            return $p2;
        });

    $h->step('dataSources', 'executePool', 'executePool: 3 data-source GETs (one 404) via returnRequest',
        function (APIClient $c) use ($h, $ds, $ds2, $absentType) {
            $res = $c->executePool([
                $c->dataSources->get($ds->id, returnRequest: true),
                $c->dataSources->get($absentType, returnRequest: true),
                $c->dataSources->get($ds2?->id ?? $ds->id, (new Query())->fields('data-source', 'title'), returnRequest: true),
            ], 3);
            $h->assert(count($res) === 3, '3 results');
            $h->assert($res[0] instanceof DataSource && $res[0]->id === $ds->id, 'first hydrated');
            $h->assert($res[1] instanceof \nickdnk\Klaviyo\Exceptions\ClientException && $res[1]->getHttpStatus() === 404, 'second is a 404 exception');
            $h->assert($res[2] instanceof DataSource, 'third hydrated');

            return $res;
        });

    // ───────────────────────────── Object type + inline schema ─────────────────────────────

    $props = [
        ['id' => 1, 'name' => 'subscription_id', 'type' => 'string', 'description' => 'Upstream primary key'],
        ['id' => 2, 'name' => 'service_plan', 'type' => 'string', 'description' => 'Plan name'],
        ['id' => 3, 'name' => 'visits_per_month', 'type' => 'int'],
        ['id' => 4, 'name' => 'monthly_rate', 'type' => 'float'],
        ['id' => 5, 'name' => 'is_active', 'type' => 'boolean'],
        ['id' => 6, 'name' => 'next_appointment', 'type' => 'timestamp'],
        ['id' => 7, 'name' => 'labels', 'type' => 'list_str'],
    ];

    $propertyMappings = [
        ['id' => 1, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['subscription_id']"]],
        ['id' => 2, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['service_plan']"]],
        ['id' => 3, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['visits_per_month']"]],
        ['id' => 4, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['monthly_rate']"]],
        ['id' => 5, 'type' => 'constant', 'value' => true],
        ['id' => 6, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['next_appointment']"]],
        ['id' => 7, 'type' => 'simple', 'source' => ['source_id' => $ds->id, 'id_path' => "$['object_record']['subscription_id']", 'data_path' => "$['object_record']['labels']"]],
    ];

    $inlineSchema = function () use ($props, $propertyMappings): ObjectSchema {
        $mapping = new SourceMapping();
        $mapping->property_mappings = $propertyMappings;
        $schema = new ObjectSchema();
        $schema->properties = $props;
        $schema->required = ['subscription_id'];
        $schema->status = 'active';
        $schema->{'source-mapping'} = $mapping->wrapData();

        return $schema;
    };

    /** @var ObjectType|null $type */
    $type = $h->step('objectTypes', 'create', 'create object type (title, description, visibility, namespace + inline active schema w/ 7 typed properties, required, source mapping)',
        fn(APIClient $c) => $c->objectTypes->create(
            new CreateObjectType($h->name('type'), 'Smoke-test object type', 'private', 'custom-objects', $inlineSchema()),
            (new Query())->fields('object-type', 'title', 'description', 'status', 'namespace', 'created_at', 'updated_at')
        ),
        fn($r) => $h->assert(
            $r instanceof ObjectType && $r->id && $r->title === $h->name('type')
            && $r->namespace === 'custom-objects' && in_array($r->status, ['active', 'draft', 'publishing'], true),
            'hydrated object type with status'
        ));
    if ($type) {
        $h->cleanup('objectTypes', 'delete', 'object type', fn(APIClient $c) => $c->objectTypes->delete($type->id));
    }

    /** @var ObjectType|null $type2 */
    $type2 = $h->step('objectTypes', 'create', 'create second object type (far side of the schema linkage)',
        fn(APIClient $c) => $c->objectTypes->create(
            new CreateObjectType($h->name('type2'), 'Smoke-test linked object type', 'private', 'custom-objects', (function () {
                $s = new ObjectSchema();
                $s->properties = [
                    ['id' => 1, 'name' => 'pet_id', 'type' => 'string'],
                    ['id' => 2, 'name' => 'pet_name', 'type' => 'string'],
                ];
                $s->required = ['pet_id'];
                $s->status = 'active';

                return $s;
            })())
        ),
        fn($r) => $h->assert($r instanceof ObjectType && $r->id, 'hydrated'));
    if ($type2) {
        $h->cleanup('objectTypes', 'delete', 'object type 2', fn(APIClient $c) => $c->objectTypes->delete($type2->id));
    }

    $gated = $type === null;
    if ($gated) {
        $h->note(
            'Custom objects are gated on this account: POST /api/object-types answers 403 '
            . '"Entitlement to create object type not found." (code `forbidden`). Data sources and record '
            . 'ingestion jobs (POST /api/data-sources, /api/data-source-record-create-jobs, '
            . '/api/data-source-record-bulk-create-jobs) all work; everything that needs an object type — '
            . 'the 17 ObjectTypeService methods, all of ObjectSchemaService, SourceMappingService and '
            . 'ObjectRecordService — was therefore called against a well-formed but non-existent ULID so '
            . 'each method is still exercised. Those steps report 404 (or null, where the SDK maps 404 to '
            . 'null), not a request-shape problem. Async polling of ingestion logs/records is skipped.'
        );
    }

    $typeId  = $type->id ?? $absentType;
    $type2Id = $type2->id ?? $absentType2;

    $h->step('objectTypes', 'get', 'get object type w/ fields[object-type] + fields[object-schema] + include(current-schema,draft-schema)',
        fn(APIClient $c) => $c->objectTypes->get($typeId, (new Query())
            ->fields('object-type', 'title', 'description', 'status', 'namespace', 'created_at', 'updated_at')
            ->fields('object-schema', 'title', 'status', 'properties', 'required', 'visibility')
            ->include('current-schema', 'draft-schema')),
        function ($r) use ($h, $gated, $typeId) {
            if ($gated) {
                $h->assert($r === null, 'null on 404 for unknown object type');

                return;
            }
            $h->assert($r instanceof ObjectType && $r->id === $typeId && $r->title !== null, 'hydrated');
            $h->assert($r->getRelationship('current-schema') !== null, 'current-schema relationship hydrated');
        });

    $h->step('objectTypes', 'get', 'get object type w/ include(object-types,profile-object-types,schema-versions) + fields[profile-object-type]',
        fn(APIClient $c) => $c->objectTypes->get($typeId, (new Query())
            ->include('object-types', 'profile-object-types', 'schema-versions')
            ->fields('profile-object-type', 'title')),
        fn($r) => $h->assert($gated ? $r === null : $r instanceof ObjectType, $gated ? 'null on 404' : 'hydrated'));

    $h->step('objectTypes', 'list', 'list object types w/ filter equals(namespace) + include(current-schema) + fields',
        fn(APIClient $c) => $c->objectTypes->list((new Query())
            ->filter(Filter::equals('namespace', 'custom-objects'))
            ->include('current-schema')
            ->fields('object-type', 'title', 'status', 'namespace')
            ->fields('object-schema', 'title', 'status')),
        function ($r) use ($h, $gated, $type, $type2) {
            $h->assert(is_array($r['data']), 'collection');
            if (!$gated) {
                $ids = array_map(fn($t) => $t->id, $r['data']);
                $h->assert(in_array($type->id, $ids, true), 'our type listed');
                $h->assert($type2 === null || in_array($type2->id, $ids, true), 'second type listed');
            }
        });

    $h->step('objectTypes', 'list', 'list object types w/ include(draft-schema) only',
        fn(APIClient $c) => $c->objectTypes->list((new Query())->include('draft-schema')),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    $h->skip('objectTypes', 'list', 'list w/ filter contains(title,"sdk-smoke")',
        'GET /api/object-types rejects it: "\'title\' is not a filterable field for this resource. The filterable fields on this resource are: namespace." (also the only filter the spec documents). Same endpoint rejects page[size] entirely.');

    // ── schema versions
    $currentSchema = $h->step('objectTypes', 'currentSchema', 'current schema for object type w/ fields[object-schema]',
        fn(APIClient $c) => $c->objectTypes->currentSchema($typeId, (new Query())
            ->fields('object-schema', 'title', 'description', 'status', 'properties', 'required', 'published_at', 'visibility')),
        function ($r) use ($h, $gated, $props) {
            if ($gated) {
                $h->assert($r === null, 'null on 404 for unknown object type');

                return;
            }
            $h->assert($r instanceof ObjectSchemaResponse && $r->id, 'hydrated schema');
            $h->assert(is_array($r->properties) && count($r->properties) === count($props), 'all properties echoed');
            $h->assert($r->required === ['subscription_id'], 'required echoed');
        });

    $h->step('objectTypes', 'currentSchemaId', 'current schema id for object type',
        fn(APIClient $c) => $c->objectTypes->currentSchemaId($typeId),
        fn($r) => $gated
            ? $h->assert($r === null, 'null on 404')
            : $h->assert($r instanceof ObjectSchemaResponse && $r->id === $currentSchema?->id && $r->title === null, 'identifier only, same id as current-schema'));

    $schemaId = $currentSchema->id ?? $absentSchema;

    // A second schema version on the same type: this is what makes draft-schema non-empty.
    $draftSchema = $h->step('objectSchemas', 'create', 'create draft schema version for the object type (title, properties, description, status=draft, required, source mapping, object-type relationship)',
        fn(APIClient $c) => $c->objectSchemas->create(
            new CreateObjectSchema(
                $h->name('schema-v2'),
                array_merge($props, [['id' => 8, 'name' => 'renewal_count', 'type' => 'int', 'description' => 'Added in v2']]),
                'Smoke-test draft schema version',
                'draft',
                ['subscription_id', 'service_plan'],
                (function () use ($propertyMappings) {
                    $m = new SourceMapping();
                    $m->property_mappings = $propertyMappings;

                    return $m;
                })(),
                $typeId
            ),
            (new Query())->fields('object-schema', 'title', 'status', 'properties', 'required')
        ),
        fn($r) => $h->assert($r instanceof ObjectSchemaResponse && $r->id && $r->status === 'draft' && count($r->properties) === 8, 'draft schema v2 created'));

    $h->step('objectTypes', 'draftSchema', 'draft schema for object type w/ fields[object-schema]',
        fn(APIClient $c) => $c->objectTypes->draftSchema($typeId, (new Query())->fields('object-schema', 'title', 'status', 'properties')),
        function ($r) use ($h, $gated, $draftSchema) {
            if ($gated || $draftSchema === null) {
                $h->assert($r === null, 'null when there is no draft (or the type does not exist)');

                return;
            }
            $h->assert($r instanceof ObjectSchemaResponse && $r->id === $draftSchema->id, 'the draft we just created');
        });

    $h->step('objectTypes', 'draftSchemaId', 'draft schema id for object type',
        fn(APIClient $c) => $c->objectTypes->draftSchemaId($typeId),
        fn($r) => ($gated || $draftSchema === null)
            ? $h->assert($r === null, 'null')
            : $h->assert($r instanceof ObjectSchemaResponse && $r->id === $draftSchema->id, 'identifier only'));

    $h->step('objectTypes', 'schemaVersions', 'schema versions for object type w/ fields[object-schema]',
        fn(APIClient $c) => $c->objectTypes->schemaVersions($typeId, (new Query())->fields('object-schema', 'title', 'status', 'published_at')),
        function ($r) use ($h, $gated, $schemaId) {
            $h->assert(is_array($r['data']), 'collection');
            if (!$gated) {
                $ids = array_map(fn($s) => $s->id, $r['data']);
                $h->assert(in_array($schemaId, $ids, true), 'current schema among the versions');
            }
        });

    $h->step('objectTypes', 'schemaVersionIds', 'schema version ids for object type',
        fn(APIClient $c) => $c->objectTypes->schemaVersionIds($typeId),
        fn($r) => $h->assert(is_array($r['data']) && ($gated || $r['data'][0] instanceof ObjectSchemaResponse), 'identifiers hydrate to ObjectSchema'));

    $h->step('objectTypes', 'objectTypeRelationships', 'object-type ↔ object-type linkages for object type',
        fn(APIClient $c) => $c->objectTypes->objectTypeRelationships($typeId),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    $h->step('objectTypes', 'profileTypeRelationships', 'object-type ↔ profile-object-type linkages for object type',
        fn(APIClient $c) => $c->objectTypes->profileTypeRelationships($typeId),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    // ───────────────────────────── Object schemas ─────────────────────────────

    $h->step('objectSchemas', 'get', 'get object schema w/ fields (object-schema, source-mapping, profile-object-schema) + include(source-mapping,object-schemas,profile-object-schemas)',
        fn(APIClient $c) => $c->objectSchemas->get($schemaId, (new Query())
            ->fields('object-schema', 'title', 'description', 'status', 'properties', 'required', 'published_at', 'visibility')
            ->fields('source-mapping', 'property_mappings', 'relationship_mappings')
            ->fields('profile-object-schema', 'title')
            ->include('source-mapping', 'object-schemas', 'profile-object-schemas')),
        function ($r) use ($h, $gated, $schemaId) {
            if ($gated) {
                $h->assert($r === null, 'null on 404 for unknown object schema');

                return;
            }
            $h->assert($r instanceof ObjectSchemaResponse && $r->id === $schemaId, 'hydrated');
            $h->assert($r->getRelationship('source-mapping') !== null, 'source-mapping relationship hydrated');
        });

    $h->step('objectSchemas', 'get', 'get unknown object schema → null',
        fn(APIClient $c) => $c->objectSchemas->get($absentSchema2),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $draftId = $draftSchema->id ?? $absentSchema;

    $h->step('objectSchemas', 'update', 'update draft schema: title + description + properties + required + status',
        function (APIClient $c) use ($h, $draftId, $props) {
            $u = new UpdateObjectSchema($draftId);
            $u->title = $h->name('schema-v2-renamed');
            $u->description = 'Renamed by the smoke suite';
            $u->properties = array_merge($props, [
                ['id' => 8, 'name' => 'renewal_count', 'type' => 'int', 'description' => 'Added in v2'],
                ['id' => 9, 'name' => 'churn_risk', 'type' => 'float', 'description' => 'Added by PATCH'],
            ]);
            $u->required = ['subscription_id'];
            $u->status = 'draft';

            return $c->objectSchemas->update($u, (new Query())->fields('object-schema', 'title', 'description', 'status', 'properties', 'required'));
        },
        fn($r) => $h->assert($r instanceof ObjectSchemaResponse && $r->title === $h->name('schema-v2-renamed') && count($r->properties) === 9, 'renamed, 9 properties'));

    // ── object-schema ↔ object-schema linkage
    $schema2Id = $absentSchema2;
    if ($type2) {
        $s2 = $h->step('objectTypes', 'currentSchemaId', 'current schema id for the second object type',
            fn(APIClient $c) => $c->objectTypes->currentSchemaId($type2->id),
            fn($r) => $h->assert($r instanceof ObjectSchemaResponse && $r->id, 'identifier'));
        $schema2Id = $s2->id ?? $absentSchema2;
    }

    $h->step('objectSchemas', 'createObjectSchemaRelationship', 'link schema → second schema (meta.name + meta.description)',
        fn(APIClient $c) => $c->objectSchemas->createObjectSchemaRelationship($schemaId, [
            new ObjectSchemaRelationship($schema2Id, name: 'pet', description: 'The pet this subscription covers'),
        ]),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    $objectRelId = $absentObjectRelId;
    if (!$gated) {
        // POST answers 204 with no body, and hydration drops the `meta` the GET carries, so the
        // linkage id has to come off the raw payload.
        $raw = $rawGet("object-schemas/{$schemaId}/relationships/object-schemas");
        $objectRelId = $raw['data'][0]['meta']['relationship_id'] ?? $absentObjectRelId;
    }

    $h->step('objectSchemas', 'objectSchemaRelationships', 'object-schema linkages for schema',
        fn(APIClient $c) => $c->objectSchemas->objectSchemaRelationships($schemaId),
        function ($r) use ($h, $gated, $schema2Id) {
            $h->assert(is_array($r['data']), 'collection');
            if (!$gated) {
                $h->assert(in_array($schema2Id, array_map(fn($s) => $s->id, $r['data']), true), 'the linked schema is listed');
            }
        });

    $h->step('objectSchemas', 'updateObjectSchemaRelationship', 'rename the object-schema linkage (single entry, meta.relationship_id)',
        fn(APIClient $c) => $c->objectSchemas->updateObjectSchemaRelationship($schemaId,
            new ObjectSchemaRelationship($schema2Id, relationshipId: $objectRelId, name: 'covered_pet', description: 'Renamed by the smoke suite')),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    // ── object-schema ↔ profile linkage
    $h->step('objectSchemas', 'createProfileSchemaRelationship', 'link schema → profile schema (id "profile", meta.name)',
        fn(APIClient $c) => $c->objectSchemas->createProfileSchemaRelationship($schemaId, [
            new ProfileObjectSchemaRelationship('profile', name: 'owner', description: 'The profile that owns this subscription'),
        ]),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    $profileRelId = $absentProfileRelId;
    if (!$gated) {
        $raw = $rawGet("object-schemas/{$schemaId}/relationships/profile-object-schemas");
        $profileRelId = $raw['data'][0]['meta']['relationship_id'] ?? $absentProfileRelId;
    }

    $h->step('objectSchemas', 'profileSchemaRelationships', 'profile-schema linkages for schema',
        fn(APIClient $c) => $c->objectSchemas->profileSchemaRelationships($schemaId),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    $h->step('objectSchemas', 'updateProfileSchemaRelationship', 'rename the profile-schema linkage (single entry, meta.relationship_id)',
        fn(APIClient $c) => $c->objectSchemas->updateProfileSchemaRelationship($schemaId,
            new ProfileObjectSchemaRelationship('profile', relationshipId: $profileRelId, name: 'subscriber', description: 'Renamed by the smoke suite')),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    // ───────────────────────────── Source mapping ─────────────────────────────

    $sourceMapping = $h->step('objectSchemas', 'sourceMapping', 'source mapping for schema w/ fields[source-mapping]',
        fn(APIClient $c) => $c->objectSchemas->sourceMapping($schemaId, (new Query())->fields('source-mapping', 'property_mappings', 'relationship_mappings')),
        function ($r) use ($h, $gated) {
            if ($gated) {
                $h->assert($r === null, 'null on 404 for unknown schema');

                return;
            }
            $h->assert($r instanceof SourceMappingResponse && $r->id, 'hydrated source mapping');
            $h->assert(is_array($r->property_mappings), 'property_mappings present');
        });

    $h->step('objectSchemas', 'sourceMappingId', 'source mapping id for schema',
        fn(APIClient $c) => $c->objectSchemas->sourceMappingId($schemaId),
        fn($r) => $gated
            ? $h->assert($r === null, 'null')
            : $h->assert($r instanceof SourceMappingResponse && $r->id === $sourceMapping?->id, 'identifier only, same id'));

    $mappingId = $sourceMapping->id ?? $absentMapping;

    $h->step('sourceMappings', 'get', 'get source mapping w/ fields[source-mapping]',
        fn(APIClient $c) => $c->sourceMappings->get($mappingId, (new Query())->fields('source-mapping', 'property_mappings', 'relationship_mappings')),
        fn($r) => $gated
            ? $h->assert($r === null, 'null on 404')
            : $h->assert($r instanceof SourceMappingResponse && $r->id === $mappingId, 'hydrated'));

    $h->step('sourceMappings', 'get', 'get unknown source mapping → null',
        fn(APIClient $c) => $c->sourceMappings->get($absentMapping),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('sourceMappings', 'update', 'update source mapping: property_mappings (simple + constant) + relationship_mappings (custom-object + profile)',
        fn(APIClient $c) => $c->sourceMappings->update(
            new UpdateSourceMapping(
                $mappingId,
                $propertyMappings,
                [
                    [
                        'relationship_id' => $objectRelId,
                        'type'            => 'simple',
                        'source'          => [
                            'source_id'       => $ds->id,
                            'type'            => 'custom-object',
                            'id_path'         => "$['object_record']['subscription_id']",
                            'related_id_path' => "$['object_record']['pet_id']",
                            'update_strategy' => 'replace_from_origin',
                        ],
                    ],
                    [
                        'relationship_id' => $profileRelId,
                        'type'            => 'simple',
                        'source'          => [
                            'source_id'        => $ds->id,
                            'type'             => 'profile',
                            'id_path'          => "$['relationships']['owners'][*]['id']",
                            'related_id_paths' => [
                                ['identifier_type' => 'email', 'path' => "$['relationships']['owners'][*]['email']"],
                            ],
                            'update_strategy'  => 'additive',
                        ],
                    ],
                ]
            ),
            (new Query())->fields('source-mapping', 'property_mappings', 'relationship_mappings')
        ),
        fn($r) => $h->assert($r instanceof SourceMappingResponse && count($r->property_mappings ?? []) === 7, 'mapping rewritten'));

    // ───────────────────────────── Record ingestion ─────────────────────────────

    $recordKeys = [$h->name('rec-1'), $h->name('rec-2'), $h->name('rec-3')];

    $h->step('dataSources', 'createRecord', 'ingest one record (all seven typed fields + owners relationship block)',
        fn(APIClient $c) => $c->dataSources->createRecord(new CreateDataSourceRecordJob([
            'object_record'  => [
                'subscription_id'  => $recordKeys[0],
                'service_plan'     => 'Monthly Grooming',
                'visits_per_month' => 2,
                'monthly_rate'     => 79.99,
                'is_active'        => true,
                'next_appointment' => (new DateTimeImmutable('+7 days'))->format(DATE_ATOM),
                'labels'           => ['smoke', 'sdk'],
                'pet_id'           => $h->name('pet-1'),
            ],
            'relationships'  => [
                'owners' => [['id' => $recordKeys[0], 'email' => $h->email('owner')]],
            ],
        ], $ds->id)),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    $h->step('dataSources', 'bulkCreateRecords', 'ingest two more records in one bulk job',
        fn(APIClient $c) => $c->dataSources->bulkCreateRecords(new BulkCreateDataSourceRecordsJob([
            [
                'object_record' => [
                    'subscription_id'  => $recordKeys[1],
                    'service_plan'     => 'Annual Grooming',
                    'visits_per_month' => 24,
                    'monthly_rate'     => 59.5,
                    'is_active'        => true,
                    'next_appointment' => (new DateTimeImmutable('+14 days'))->format(DATE_ATOM),
                    'labels'           => ['smoke'],
                ],
            ],
            [
                'object_record' => [
                    'subscription_id'  => $recordKeys[2],
                    'service_plan'     => 'Trial',
                    'visits_per_month' => 1,
                    'monthly_rate'     => 0.0,
                    'is_active'        => false,
                    'next_appointment' => (new DateTimeImmutable('+1 day'))->format(DATE_ATOM),
                    'labels'           => [],
                ],
            ],
        ], $ds->id)),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    $h->step('dataSources', 'bulkCreateRecords', 'bulk job with a record that violates the schema (int in a string field) → surfaces in the ingestion log',
        fn(APIClient $c) => $c->dataSources->bulkCreateRecords(new BulkCreateDataSourceRecordsJob([
            ['object_record' => ['subscription_id' => 12345, 'service_plan' => ['not', 'a', 'string']]],
        ], $ds->id)),
        fn($r) => $h->assert($r === null, '204 with empty body (validation happens asynchronously)'));

    // ── ingestion logs
    if ($gated) {
        $h->skip('objectTypes', 'ingestionLogs', 'poll ingestion logs until the bad record appears', 'prerequisite failed (403)');
        $h->skip('objectTypes', 'records', 'poll records until the ingested records appear', 'prerequisite failed (403)');
    } else {
        $h->step('objectTypes', 'ingestionLogs', 'poll ingestion logs until the invalid record is logged',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $typeId) {
                $logs = $c->objectTypes->ingestionLogs($typeId, (new Query())
                    ->fields('object-ingestion-log', 'status', 'event_type', 'timestamp', 'summary', 'errors')
                    ->filter(Filter::all(
                        Filter::equals('status', 'error'),
                        Filter::greaterOrEqual('timestamp', new DateTimeImmutable('-1 hour')),
                    )));

                return $logs['data'] ? $logs : null;
            }, 180, 10, 'a validation error in the ingestion log'),
            fn($r) => $h->assert($r['data'][0] instanceof \nickdnk\Klaviyo\Resources\Response\ObjectIngestionLog && $r['data'][0]->status === 'error', 'ingestion log hydrated'));
    }

    $h->step('objectTypes', 'ingestionLogs', 'ingestion logs w/ filter any(status) + any(event_type) + timestamp range + include(object-record,object-type) + fields',
        fn(APIClient $c) => $c->objectTypes->ingestionLogs($typeId, (new Query())
            ->fields('object-ingestion-log', 'status', 'event_type', 'timestamp', 'summary', 'errors')
            ->fields('object-record', 'record_properties')
            ->fields('object-type', 'title')
            ->include('object-record', 'object-type')
            ->filter(Filter::all(
                Filter::any('status', ['error', 'warning', 'info']),
                Filter::any('event_type', ['validation_error']),
                Filter::greaterOrEqual('timestamp', new DateTimeImmutable('-1 day')),
                Filter::lessThan('timestamp', new DateTimeImmutable('+1 day')),
            ))),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    $h->step('objectTypes', 'ingestionLogIds', 'ingestion log ids w/ filter equals(status,error) + timestamp range',
        fn(APIClient $c) => $c->objectTypes->ingestionLogIds($typeId, (new Query())->filter(Filter::all(
            Filter::equals('status', 'error'),
            Filter::greaterOrEqual('timestamp', new DateTimeImmutable('-1 day')),
        ))),
        fn($r) => $h->assert(is_array($r['data']), 'collection'));

    // ── records
    $recordIds = [];
    if ($gated) {
        $h->step('objectTypes', 'records', 'records for object type w/ fields[object-record] + page[size]=2',
            fn(APIClient $c) => $c->objectTypes->records($typeId, (new Query())->fields('object-record', 'record_properties')->pageSize(2)),
            fn($r) => $h->assert(is_array($r['data']), 'collection'));
        $h->step('objectTypes', 'recordIds', 'record ids for object type w/ page[size]=2',
            fn(APIClient $c) => $c->objectTypes->recordIds($typeId, (new Query())->pageSize(2)),
            fn($r) => $h->assert(is_array($r['data']), 'collection'));
    } else {
        $records = $h->step('objectTypes', 'records', 'poll records until the two valid records land, w/ fields[object-record] + page[size]=2 + links.next',
            fn(APIClient $c) => $h->waitFor(function () use ($c, $h, $typeId) {
                $page1 = $c->objectTypes->records($typeId, (new Query())->fields('object-record', 'record_properties')->pageSize(2));
                if (count($page1['data']) < 2) {
                    return null;
                }
                $all = $page1['data'];
                if ($page1['links']?->next) {
                    $page2 = $c->objectTypes->records($typeId, next: $page1['links']->next);
                    $all = array_merge($all, $page2['data']);
                }

                return ['data' => $all, 'links' => $page1['links']];
            }, 300, 15, 'ingested records to appear'),
            fn($r) => $h->assert($r['data'][0] instanceof ObjectRecord && is_array($r['data'][0]->record_properties), 'records hydrated with record_properties'));
        $recordIds = array_map(fn($r) => $r->id, $records['data'] ?? []);

        $h->step('objectTypes', 'recordIds', 'record ids for object type w/ page[size]=2 + pagination',
            function (APIClient $c) use ($h, $typeId) {
                $p1 = $c->objectTypes->recordIds($typeId, (new Query())->pageSize(2));
                $h->assert(count($p1['data']) >= 1 && $p1['data'][0] instanceof ObjectRecord, 'identifiers hydrate to ObjectRecord');
                if ($p1['links']?->next) {
                    $p2 = $c->objectTypes->recordIds($typeId, next: $p1['links']->next);
                    $h->assert(is_array($p2['data']), 'second page');
                }

                return $p1;
            });
    }

    $recordId = $recordIds[0] ?? $absentRecord;

    $h->step('objectRecords', 'get', 'get object record by compound id w/ fields[object-record]',
        fn(APIClient $c) => $c->objectRecords->get($recordId, (new Query())->fields('object-record', 'record_properties')),
        fn($r) => $gated
            ? $h->assert($r === null, 'null on 404 for unknown compound id')
            : $h->assert($r instanceof ObjectRecord && $r->id === $recordId && is_array($r->record_properties), 'hydrated record'));

    $h->step('objectRecords', 'get', 'get unknown object record → null',
        fn(APIClient $c) => $c->objectRecords->get($absentRecord),
        fn($r) => $h->assert($r === null, 'null on 404'));

    $h->step('objectRecords', 'bulkDelete', 'bulk delete the ingested records',
        fn(APIClient $c) => $c->objectRecords->bulkDelete(new BulkDeleteObjectRecordsJob($recordIds ?: [$absentRecord])),
        fn($r) => $h->assert($r === null, '202 with empty body'));

    // ───────────────────────────── Linkage teardown ─────────────────────────────

    $h->step('objectSchemas', 'deleteProfileSchemaRelationship', 'unlink the profile-schema linkage (meta.relationship_id)',
        fn(APIClient $c) => $c->objectSchemas->deleteProfileSchemaRelationship($schemaId, [
            new ProfileObjectSchemaRelationship('profile', relationshipId: $profileRelId),
        ]),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    $h->step('objectSchemas', 'deleteObjectSchemaRelationship', 'unlink the object-schema linkage (meta.relationship_id)',
        fn(APIClient $c) => $c->objectSchemas->deleteObjectSchemaRelationship($schemaId, [
            new ObjectSchemaRelationship($schema2Id, relationshipId: $objectRelId),
        ]),
        fn($r) => $h->assert($r === null, '204 with empty body'));

    if ($gated) {
        // The real delete runs as a cleanup hook when the type could be created; with the
        // entitlement off, exercise the endpoint against the placeholder id (HasDelete
        // swallows 404 and returns null).
        $h->step('objectTypes', 'delete', 'delete object type (placeholder id — creates are gated; 404 swallowed by HasDelete)',
            fn(APIClient $c) => $c->objectTypes->delete($absentType),
            fn($r) => $h->assert($r === null, 'null, 404 swallowed'));
    }

    $h->note(
        'Three object-schema linkage endpoints answer HTTP 500 "A server error occurred." for an object-schema '
        . 'id that does not exist, where every sibling endpoint answers a clean 404: GET '
        . '/api/object-schemas/{id}/relationships/object-schemas, GET '
        . '/api/object-schemas/{id}/relationships/profile-object-schemas and POST '
        . '/api/object-schemas/{id}/relationships/profile-object-schemas. The request bodies match '
        . 'ObjectSchemaRelationshipCreateQuery / ProfileObjectSchemaRelationshipCreateQuery exactly, so this '
        . 'looks like a Klaviyo-side bug, not an SDK one. PATCH on the same paths does answer 404.'
    );

    if ($gated) {
        $h->note(
            'ObjectSchemaService linkage `relationship_id`s cannot be obtained through the SDK: POST '
            . '/relationships/object-schemas answers 204 with no body, and the GET that carries them puts '
            . 'them in each entry\'s `meta`, which hydration drops (documented on the service). '
            . 'updateObjectSchemaRelationship / deleteObjectSchemaRelationship therefore need an id the '
            . 'caller has no SDK-supported way to read; this suite falls back to a raw cURL GET.'
        );
    }

    $h->mutation(
        'Ingested 6 raw records into the smoke-test data sources (POST /api/data-source-record-*-jobs answers 204 '
        . 'and there is no object type to map them onto, so they stay unmapped raw payloads). Deleting the data '
        . 'sources in cleanup is the only handle the API offers; ingestion logs for them are unreachable without '
        . 'an object type and expire after 14 days.'
    );
};
