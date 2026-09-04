<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CouponCodeBulkCreateJob extends IdentifiableResource
{
    public static function type(): string
    {

        return 'coupon-code-bulk-create-job';
    }
}
