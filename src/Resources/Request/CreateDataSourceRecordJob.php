<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\DataSource;
use nickdnk\Klaviyo\Resources\Shared\DataSourceRecord;
use nickdnk\Klaviyo\Resources\Shared\DataSourceRecordCreateJob;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/data-source-record-create-jobs: ingests a single record, at most 512KB.
 *
 * The record rides as the hyphenated `data-source-record` attribute — a `{data: {...}}`
 * envelope nested inside `attributes`, not a JSON:API relationship — while the data source
 * it lands in is a real relationship. Klaviyo answers 204 with an empty body.
 */
class CreateDataSourceRecordJob extends DataSourceRecordCreateJob
{

    /**
     * @param array<string, mixed> $record the record's own fields, shaped by the target object schema
     */
    public function __construct(array $record, ?string $dataSourceId = null)
    {

        parent::__construct();

        $entry = new DataSourceRecord();
        $entry->record = $record;
        $this->{'data-source-record'} = $entry->wrapData();

        if ($dataSourceId !== null) {
            $this->addRelationship('data-source', new Relationship(new DataSource($dataSourceId)));
        }
    }

}
