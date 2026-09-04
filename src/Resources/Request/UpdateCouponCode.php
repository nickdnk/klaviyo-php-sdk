<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CouponCode;

/**
 * PATCH /api/coupon-codes/{id}. Klaviyo accepts `status` and `expires_at` only; the code
 * itself and its coupon are fixed.
 *
 * @property string|null $status      ASSIGNED_TO_PROFILE|DELETING|PROCESSING|UNASSIGNED|USED|VERSION_NOT_ACTIVE
 * @property string|null $expires_at  ISO 8601
 */
class UpdateCouponCode extends CouponCode
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
