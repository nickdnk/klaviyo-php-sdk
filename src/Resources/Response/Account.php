<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;

/**
 * @property bool               $test_account
 * @property ContactInformation $contact_information
 * @property string|null        $industry
 * @property string             $timezone
 * @property string             $preferred_currency
 * @property string             $public_api_key
 * @property string             $locale
 */
class Account extends IdentifiableResource
{

    public static function type(): string
    {

        return 'account';
    }

    protected static function nested(): array
    {

        return ['contact_information' => ContactInformation::class];
    }
}
