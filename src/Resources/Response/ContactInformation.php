<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property string        $default_sender_name
 * @property string        $default_sender_email
 * @property string|null   $website_url
 * @property string        $organization_name
 * @property StreetAddress $street_address
 */
class ContactInformation extends Resource
{

    protected static function nested(): array
    {

        return ['street_address' => StreetAddress::class];
    }
}
