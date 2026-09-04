<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Coupon;

/**
 * PATCH /api/coupons/{id}. `external_id` is immutable, so only `description` and
 * `monitor_configuration` can be sent.
 *
 * @property string|null $description
 * @property array|null  $monitor_configuration
 */
class UpdateCoupon extends Coupon
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
