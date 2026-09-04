<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * One record handed to a data source for ingestion. `record` is the payload itself: an
 * `object_record` object shaped by the target object schema, plus an optional `relationships`
 * object linking it to profiles or other records.
 *
 * @property array $record
 */
class DataSourceRecord extends IdentifiableResource
{
    public static function type(): string
    {

        return 'data-source-record';
    }
}
