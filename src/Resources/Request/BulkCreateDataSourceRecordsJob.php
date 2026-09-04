<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\DataSource;
use nickdnk\Klaviyo\Resources\Shared\DataSourceRecord;
use nickdnk\Klaviyo\Resources\Shared\DataSourceRecordBulkCreateJob;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/data-source-record-bulk-create-jobs: up to 500 records per job, 4MB per payload
 * and 512KB per record.
 *
 * The batch rides as the hyphenated `data-source-records` attribute — a `{data: [...]}`
 * envelope nested inside `attributes`, not a JSON:API relationship — while the data source
 * the records land in is a real relationship. Klaviyo answers 204 with an empty body.
 */
class BulkCreateDataSourceRecordsJob extends DataSourceRecordBulkCreateJob
{

    /**
     * @param array<int, array<string, mixed>> $records each record's own fields, shaped by the
     *                                                  target object schema
     */
    public function __construct(array $records, ?string $dataSourceId = null)
    {

        parent::__construct();

        $this->{'data-source-records'} = DataSourceRecord::wrapDataMany(
            array_map(static function (array $record) {
                $entry = new DataSourceRecord();
                $entry->record = $record;

                return $entry;
            }, array_values($records))
        );

        if ($dataSourceId !== null) {
            $this->addRelationship('data-source', new Relationship(new DataSource($dataSourceId)));
        }
    }

}
