<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $email
 * @property string|null $phone_number
 */
class Profile extends IdentifiableResource
{
    public static function type(): string
    {

        return 'profile';
    }
}
