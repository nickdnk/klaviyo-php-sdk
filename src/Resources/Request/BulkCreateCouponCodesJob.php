<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/coupon-code-bulk-create-jobs: up to 1000 coupon codes per job, at most 100
 * jobs queued at once. Each embedded code keeps its own `coupon` relationship, so one job
 * can spread codes across several coupons.
 *
 * The attribute key is the hyphenated `coupon-codes`, unlike every other Klaviyo bulk job,
 * so it is set through dynamic property access rather than as `coupon_codes`.
 */
class BulkCreateCouponCodesJob extends TypedResource
{

    /**
     * @param CreateCouponCode[] $couponCodes
     */
    public function __construct(array $couponCodes)
    {

        $this->{'coupon-codes'} = CreateCouponCode::wrapDataMany(array_values($couponCodes));
    }

    public static function type(): string
    {

        return 'coupon-code-bulk-create-job';
    }

}
