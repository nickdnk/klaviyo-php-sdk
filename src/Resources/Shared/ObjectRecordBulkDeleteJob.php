<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class ObjectRecordBulkDeleteJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'object-record-bulk-delete-job';
    }
}
