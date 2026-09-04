<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogCategoriesJob;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogCategory;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogCategoryBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItem as ResponseCatalogItem;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasBulkJobs;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The groupings a catalog's items are sorted into.
 *
 * - A category id is composite: integration type, catalog type and external id joined by `:::`,
 *   e.g. `$custom:::$default:::SAMPLE-DATA-CATEGORY-1`. Treat it as an opaque string.
 * - Items and categories are linked from both ends: a category's items are managed here, the
 *   categories of one item on {@see CatalogItemService}.
 * - Relationship writes are not idempotent: re-adding an existing link answers 409.
 * - Batches of up to 100 categories go through the bulk-job families instead of the synchronous
 *   writes, with at most 500 jobs in progress per account.
 *
 * @link https://developers.klaviyo.com/en/reference/catalogs_api_overview
 */
class CatalogCategoryService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_BULK_CREATE_JOBS = 'catalog-category-bulk-create-jobs';
    private const string PATH_BULK_UPDATE_JOBS = 'catalog-category-bulk-update-jobs';
    private const string PATH_BULK_DELETE_JOBS = 'catalog-category-bulk-delete-jobs';

    /**
     * Sortable by `created` only.
     *
     * @link https://developers.klaviyo.com/en/reference/get_catalog_categories
     * @return array{data: CatalogCategory[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_catalog_category
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CatalogCategory|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_catalog_category
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCatalogCategory $category, ?Query $query = null, bool $returnRequest = false): CatalogCategory|RequestInterface
    {

        return $this->createTrait($category, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_catalog_category
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCatalogCategory $category, ?Query $query = null, bool $returnRequest = false): CatalogCategory|RequestInterface
    {

        return $this->updateTrait($category, $query, $returnRequest);

    }

    // region Relationships

    /**
     * Sortable by `created` only.
     *
     * @link https://developers.klaviyo.com/en/reference/get_items_for_catalog_category
     * @return array{data: ResponseCatalogItem[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function items(string $categoryId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($categoryId, 'items', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_item_ids_for_catalog_category
     * @return array{data: ResponseCatalogItem[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function itemIds(string $categoryId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($categoryId, 'items', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/add_items_to_catalog_category
     * @param CatalogItem[] $items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function addItems(string $categoryId, array $items, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($categoryId, 'items', $items, $returnRequest);

    }

    /**
     * Replaces the category's whole item set.
     *
     * @link https://developers.klaviyo.com/en/reference/update_items_for_catalog_category
     * @param CatalogItem[] $items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function replaceItems(string $categoryId, array $items, bool $returnRequest = false): ?RequestInterface
    {

        return $this->replaceRelatedTrait($categoryId, 'items', $items, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_items_from_catalog_category
     * @param CatalogItem[] $items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function removeItems(string $categoryId, array $items, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($categoryId, 'items', $items, $returnRequest);

    }

    // endregion

    // region Bulk create jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_create_catalog_categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreate(BulkCreateCatalogCategoriesJob $job, bool $returnRequest = false): CatalogCategoryBulkCreateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_CREATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_categories_jobs
     * @return array{data: CatalogCategoryBulkCreateJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_categories_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkCreateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogCategoryBulkCreateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_CREATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk update jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_update_catalog_categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkUpdate(BulkUpdateCatalogCategoriesJob $job, bool $returnRequest = false): CatalogCategoryBulkUpdateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_UPDATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_categories_jobs
     * @return array{data: CatalogCategoryBulkUpdateJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_categories_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkUpdateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogCategoryBulkUpdateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_UPDATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk delete jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_delete_catalog_categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkDelete(BulkDeleteCatalogCategoriesJob $job, bool $returnRequest = false): CatalogCategoryBulkDeleteJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_DELETE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_categories_jobs
     * @return array{data: CatalogCategoryBulkDeleteJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_categories_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkDeleteJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogCategoryBulkDeleteJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_DELETE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'catalog-categories';

    }

}
