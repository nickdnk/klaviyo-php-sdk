<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkDeleteCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\BulkUpdateCatalogItemsJob;
use nickdnk\Klaviyo\Resources\Request\CreateCatalogItem;
use nickdnk\Klaviyo\Resources\Request\UpdateCatalogItem;
use nickdnk\Klaviyo\Resources\Response\CatalogCategory;
use nickdnk\Klaviyo\Resources\Response\CatalogItem;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkCreateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkDeleteJob;
use nickdnk\Klaviyo\Resources\Response\CatalogItemBulkUpdateJob;
use nickdnk\Klaviyo\Resources\Response\CatalogVariant;
use nickdnk\Klaviyo\Resources\Shared\CatalogCategory as SharedCatalogCategory;
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
 * The products of a Klaviyo catalog. An item owns its variants ({@see CatalogVariantService}) and
 * belongs to any number of categories ({@see CatalogCategoryService}).
 *
 * - An item id is composite: integration type, catalog type and external id joined by `:::`,
 *   e.g. `$custom:::$default:::SAMPLE-DATA-ITEM-1`. Treat it as an opaque string.
 * - Deleting an item deletes its variants with it.
 * - Batches of up to 100 items go through the bulk-job families instead of the synchronous
 *   writes; a job can report `processing` for minutes after its items are already readable.
 *
 * @link https://developers.klaviyo.com/en/reference/catalogs_api_overview
 */
class CatalogItemService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_BULK_CREATE_JOBS = 'catalog-item-bulk-create-jobs';
    private const string PATH_BULK_UPDATE_JOBS = 'catalog-item-bulk-update-jobs';
    private const string PATH_BULK_DELETE_JOBS = 'catalog-item-bulk-delete-jobs';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_catalog_items
     * @return array{data: CatalogItem[], links: ?PaginationLinks}|RequestInterface
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
     * Every CatalogItem matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, CatalogItem>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function iterate(?Query $query = null): Generator
    {
        return (new Paginator(fn(?string $next) => $this->list($query, $next)))->items();
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_catalog_item
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CatalogItem|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_catalog_item
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCatalogItem $item, ?Query $query = null, bool $returnRequest = false): CatalogItem|RequestInterface
    {

        return $this->createTrait($item, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_catalog_item
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCatalogItem $item, ?Query $query = null, bool $returnRequest = false): CatalogItem|RequestInterface
    {

        return $this->updateTrait($item, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_categories_for_catalog_item
     * @return array{data: CatalogCategory[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function categories(string $itemId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($itemId, 'categories', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_category_ids_for_catalog_item
     * @return array{data: CatalogCategory[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function categoryIds(string $itemId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($itemId, 'categories', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/add_categories_to_catalog_item
     * @param SharedCatalogCategory[] $categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function addCategories(string $itemId, array $categories, bool $returnRequest = false): ?RequestInterface
    {

        return $this->addRelatedTrait($itemId, 'categories', $categories, $returnRequest);

    }

    /**
     * Replaces the item's whole category set.
     *
     * @link https://developers.klaviyo.com/en/reference/update_categories_for_catalog_item
     * @param SharedCatalogCategory[] $categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function replaceCategories(string $itemId, array $categories, bool $returnRequest = false): ?RequestInterface
    {

        return $this->replaceRelatedTrait($itemId, 'categories', $categories, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/remove_categories_from_catalog_item
     * @param SharedCatalogCategory[] $categories
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function removeCategories(string $itemId, array $categories, bool $returnRequest = false): ?RequestInterface
    {

        return $this->removeRelatedTrait($itemId, 'categories', $categories, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_variants_for_catalog_item
     * @return array{data: CatalogVariant[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function variants(string $itemId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($itemId, 'variants', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_variant_ids_for_catalog_item
     * @return array{data: CatalogVariant[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function variantIds(string $itemId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($itemId, 'variants', $query, $next, $returnRequest);

    }

    // endregion

    // region Bulk create jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_create_catalog_items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreate(BulkCreateCatalogItemsJob $job, bool $returnRequest = false): CatalogItemBulkCreateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_CREATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_catalog_items_jobs
     * @return array{data: CatalogItemBulkCreateJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_catalog_items_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkCreateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogItemBulkCreateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_CREATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk update jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_update_catalog_items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkUpdate(BulkUpdateCatalogItemsJob $job, bool $returnRequest = false): CatalogItemBulkUpdateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_UPDATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_catalog_items_jobs
     * @return array{data: CatalogItemBulkUpdateJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_update_catalog_items_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkUpdateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogItemBulkUpdateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_UPDATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    // region Bulk delete jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_delete_catalog_items
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkDelete(BulkDeleteCatalogItemsJob $job, bool $returnRequest = false): CatalogItemBulkDeleteJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_DELETE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_catalog_items_jobs
     * @return array{data: CatalogItemBulkDeleteJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_delete_catalog_items_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkDeleteJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CatalogItemBulkDeleteJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_DELETE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'catalog-items';

    }

}
