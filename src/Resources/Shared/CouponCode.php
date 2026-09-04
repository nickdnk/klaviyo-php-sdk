<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CouponCode extends IdentifiableResource
{
    public static function type(): string
    {

        return 'coupon-code';
    }
}
