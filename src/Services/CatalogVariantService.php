<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogVariantsJob;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogVariant;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogVariant;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariantBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasBulkJobs;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The purchasable variants of a catalog item, and the level a back in stock subscription
 * ({@see BackInStockSubscriptionService}) attaches to, since inventory is tracked here.
 *
 * - A variant id is composite: integration type, catalog type and external id joined by `:::`,
 *   e.g. `$custom:::$default:::SAMPLE-DATA-ITEM-1-VARIANT-1`. Treat it as an opaque string.
 * - The parent item is fixed at creation and cannot be changed afterwards; a variant's siblings
 *   are listed by {@see CatalogItemService::variants()}.
 * - `include=item` is rejected when reading a single variant.
 * - Batches of up to 100 variants go through the bulk-job families instead of the synchronous
 *   writes, with at most 500 jobs in progress per account.
 *
 * @link https://developers.klaviyo.com/en/reference/catalogs_api_overview
 */
class CatalogVariantService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasUpdate;

    private const string PATH_BULK_CREATE_JOBS = 'catalog-variant-bulk-create-jobs';
    private const string PATH_BULK_UPDATE_JOBS = 'catalog-variant-bulk-update-jobs';
    private const string PATH_BULK_DELETE_JOBS = 'catalog-variant-bulk-delete-jobs';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_catalog_variants
     * @return array{data: CatalogVariant[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_catalog_variant
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CatalogVariant|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_catalog_variant
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCatalogVariant $variant, ?Query $query = null, bool $returnRequest = false): CatalogVariant|RequestInterface
    {

        return $this->createTrait($variant, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_catalog_variant
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCatalogVariant $variant, ?Query $query = null, bool $returnRequest = false): CatalogVariant|RequestInterface
    {

        return $this->updateTrait($variant, $query, $returnRequest);

    }

    // region Bulk create jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_create_catalog_variants
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreate(BulkCreateCatalogVariantsJob $job, bool $returnRequest = false): CatalogVariantBulkCreateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_CREATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_variants_jobs
     * @return array{data: CatalogVariantBulkCreateJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkCreateJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobList(self::PATH_BULK_CREATE_JOBS, $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_variants_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkCreateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogVariantBulkCreateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_CREATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk update jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_update_catalog_variants
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkUpdate(BulkUpdateCatalogVariantsJob $job, bool $returnRequest = false): CatalogVariantBulkUpdateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_UPDATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_variants_jobs
     * @return array{data: CatalogVariantBulkUpdateJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkUpdateJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobList(self::PATH_BULK_UPDATE_JOBS, $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_variants_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkUpdateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogVariantBulkUpdateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_UPDATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk delete jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_delete_catalog_variants
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkDelete(BulkDeleteCatalogVariantsJob $job, bool $returnRequest = false): CatalogVariantBulkDeleteJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_DELETE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_variants_jobs
     * @return array{data: CatalogVariantBulkDeleteJob[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkDeleteJobs(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->bulkJobList(self::PATH_BULK_DELETE_JOBS, $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_variants_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkDeleteJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogVariantBulkDeleteJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_DELETE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'catalog-variants';

    }

}
