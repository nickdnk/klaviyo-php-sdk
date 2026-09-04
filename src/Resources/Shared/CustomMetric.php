<?php


namespace nickdnk\Klaviyo\Resources\Shared;

class CustomMetric extends IdentifiableResource
{
    public static function type(): string
    {

        return 'custom-metric';
    }
}
