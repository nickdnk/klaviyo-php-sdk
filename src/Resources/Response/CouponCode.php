<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CouponCode as SharedCouponCode;

/**
 * @property string|null $unique_code
 * @property string|null $expires_at
 * @property string|null $status       ASSIGNED_TO_PROFILE|DELETING|PROCESSING|UNASSIGNED|USED|VERSION_NOT_ACTIVE
 */
class CouponCode extends SharedCouponCode
{

}
