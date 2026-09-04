<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\ObjectRecord;
use nickdnk\Klaviyo\Resources\Shared\ObjectRecordBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/object-record-bulk-delete-jobs: up to 500 record ids per job, 5MB per payload.
 * The records ride in the `object-records` relationship — the job carries no attributes at
 * all. Klaviyo answers 202 with an empty body.
 */
class BulkDeleteObjectRecordsJob extends ObjectRecordBulkDeleteJob
{

    /**
     * @param string[] $recordIds compound object-record ids
     */
    public function __construct(array $recordIds)
    {

        parent::__construct();
        $this->addRelationship(
            'object-records',
            new Relationship(array_map(static fn(string $id) => new ObjectRecord($id), array_values($recordIds)))
        );
    }

}
