<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\BulkCreateCouponCodesJob;
use nickdnk\Klaviyo\Resources\Request\CreateCouponCode;
use nickdnk\Klaviyo\Resources\Request\UpdateCouponCode;
use nickdnk\Klaviyo\Resources\Response\Coupon;
use nickdnk\Klaviyo\Resources\Response\CouponCode;
use nickdnk\Klaviyo\Resources\Response\CouponCodeBulkCreateJob;
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
 * The individual codes handed out to profiles, each belonging to a coupon
 * ({@see CouponService}).
 *
 * - {@see self::create()} makes one code synchronously; {@see self::bulkCreate()} queues up to
 *   1000 per job, with at most 100 jobs queued per account.
 * - A code already assigned to a profile cannot be deleted.
 */
class CouponCodeService extends BaseService
{

    use HasBulkJobs;
    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;
    use HasUpdate;

    private const string PATH_BULK_CREATE_JOBS = 'coupon-code-bulk-create-jobs';

    /**
     * A filter on coupons or profiles is required, e.g. `any(coupon.id,["10OFF"])`.
     *
     * @link https://developers.klaviyo.com/en/reference/get_coupon_codes
     * @return array{data: CouponCode[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_coupon_code
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): CouponCode|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_coupon_code
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateCouponCode $code, ?Query $query = null, bool $returnRequest = false): CouponCode|RequestInterface
    {

        return $this->createTrait($code, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_coupon_code
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateCouponCode $code, ?Query $query = null, bool $returnRequest = false): CouponCode|RequestInterface
    {

        return $this->updateTrait($code, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_coupon_for_coupon_code
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function coupon(string $codeId, ?Query $query = null, bool $returnRequest = false): Coupon|RequestInterface|null
    {

        return $this->relatedOneTrait($codeId, 'coupon', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_coupon_id_for_coupon_code
     * @return Coupon|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function couponId(string $codeId, bool $returnRequest = false): Coupon|RequestInterface|null
    {

        return $this->relatedOneIdTrait($codeId, 'coupon', $returnRequest);

    }

    // endregion

    // region Bulk create jobs

    /**
     * @link https://developers.klaviyo.com/en/reference/bulk_create_coupon_codes
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function bulkCreate(BulkCreateCouponCodesJob $job, bool $returnRequest = false): CouponCodeBulkCreateJob|RequestInterface
    {

        return $this->bulkJobSubmit(self::PATH_BULK_CREATE_JOBS, $job, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_coupon_code_jobs
     * @return array{data: CouponCodeBulkCreateJob[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_bulk_create_coupon_codes_job
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function getBulkCreateJob(string $jobId, ?Query $query = null, bool $returnRequest = false): CouponCodeBulkCreateJob|RequestInterface|null
    {

        return $this->bulkJobGet(self::PATH_BULK_CREATE_JOBS, $jobId, $query, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'coupon-codes';

    }

}
