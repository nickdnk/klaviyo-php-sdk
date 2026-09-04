<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Coupon;

/**
 * POST /api/coupons. `external_id` is the coupon's identifier in the source system and is
 * what Klaviyo templates reference; it cannot be changed afterwards
 * ({@see UpdateCoupon} only carries `description` and `monitor_configuration`).
 *
 * Server-side rules not in the spec: `external_id` must match `^[0-9_A-z]+$` (no hyphens; the
 * same regex is applied to `{id}` path parameters, so a hyphenated id answers 400 rather than
 * 404) and `monitor_configuration.low_balance_threshold` must be >= 100.
 *
 * @property string      $external_id
 * @property string|null $description
 * @property array|null  $monitor_configuration
 */
class CreateCoupon extends Coupon
{

    public function __construct(string $externalId, ?string $description = null)
    {

        parent::__construct();
        $this->external_id = $externalId;
        $this->description = $description;
    }

}
