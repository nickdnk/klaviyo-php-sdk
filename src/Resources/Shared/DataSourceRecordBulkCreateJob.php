<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class DataSourceRecordBulkCreateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'data-source-record-bulk-create-job';
    }
}
