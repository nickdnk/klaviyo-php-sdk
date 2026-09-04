<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteObjectRecordsJob;
use nickdnk\Klaviyo\Resources\Response\ObjectRecord;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use Psr\Http\Message\RequestInterface;

/**
 * Individual custom object records. Records are written through
 * {@see DataSourceService::createRecord()} and listed per type through
 * {@see ObjectTypeService::records()}; this service reads one by its compound id and
 * deletes them in batches.
 */
class ObjectRecordService extends BaseService
{

    use HasGet;

    private const string PATH_BULK_DELETE_JOBS = 'object-record-bulk-delete-jobs';

    /**
     * The id is the compound `{object-type}:{record}` identifier Klaviyo assigns.
     *
     * @link https://developers.klaviyo.com/en/reference/get_object_record
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): ObjectRecord|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * Deletes up to 500 records in one job. Klaviyo answers 202 with an empty body.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_delete_object_records
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkDelete(BulkDeleteObjectRecordsJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', self::PATH_BULK_DELETE_JOBS, $job, returnRequest: $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'object-records';
    }
}
