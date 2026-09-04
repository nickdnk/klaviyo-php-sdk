<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Coupon;
use nickdnk\Klaviyo\Resources\Shared\CouponCode;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/coupon-codes: one code for one coupon, created synchronously. The same shape
 * is embedded per code in {@see BulkCreateCouponCodesJob}, which is the way to create
 * codes in bulk (up to 1000 per job).
 *
 * The `coupon` relationship is required and names the coupon the code belongs to.
 *
 * @property string      $unique_code
 * @property string|null $expires_at  ISO 8601
 */
class CreateCouponCode extends CouponCode
{

    public function __construct(string $uniqueCode, string $couponId, ?string $expiresAt = null)
    {

        parent::__construct();
        $this->unique_code = $uniqueCode;
        $this->expires_at = $expiresAt;
        $this->addRelationship('coupon', new Relationship(new Coupon($couponId)));
    }

}
