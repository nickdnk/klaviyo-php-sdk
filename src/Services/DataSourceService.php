<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateDataSourceRecordsJob;
use nickdnk\Klaviyo\Resources\Request\CreateDataSource;
use nickdnk\Klaviyo\Resources\Request\CreateDataSourceRecordJob;
use nickdnk\Klaviyo\Resources\Response\DataSource;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use Psr\Http\Message\RequestInterface;

/**
 * Data sources — the upstream feeds custom object records are ingested from — and the two
 * ingestion job families that push records into them.
 */
class DataSourceService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;

    private const string PATH_RECORD_CREATE_JOBS      = 'data-source-record-create-jobs';
    private const string PATH_RECORD_BULK_CREATE_JOBS = 'data-source-record-bulk-create-jobs';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_data_sources
     * @return array{data: DataSource[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->listTrait($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_data_source
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): DataSource|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_data_source
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateDataSource $dataSource, ?Query $query = null, bool $returnRequest = false): DataSource|RequestInterface
    {

        return $this->createTrait($dataSource, $query, $returnRequest);

    }

    // region Record ingestion

    /**
     * Ingests one record, at most 512KB. Klaviyo answers 204 with an empty body; the record
     * surfaces later via {@see ObjectTypeService::records()} and failures via
     * {@see ObjectTypeService::ingestionLogs()}.
     *
     * @link https://developers.klaviyo.com/en/reference/create_data_source_record
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function createRecord(CreateDataSourceRecordJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', self::PATH_RECORD_CREATE_JOBS, $job, returnRequest: $returnRequest);

    }

    /**
     * Ingests up to 500 records in one job. Klaviyo answers 204 with an empty body.
     *
     * @link https://developers.klaviyo.com/en/reference/bulk_create_data_source_records
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreateRecords(BulkCreateDataSourceRecordsJob $job, bool $returnRequest = false): ?RequestInterface
    {

        return $this->request('POST', self::PATH_RECORD_BULK_CREATE_JOBS, $job, returnRequest: $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'data-sources';
    }
}
