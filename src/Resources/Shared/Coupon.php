<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class Coupon extends IdentifiableResource
{
    public static function type(): string
    {

        return 'coupon';
    }
}
