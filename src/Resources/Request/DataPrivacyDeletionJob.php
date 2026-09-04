<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * @property array{data: Profile} $profile
 */
class DataPrivacyDeletionJob extends TypedResource
{
    public static function type(): string
    {

        return 'data-privacy-deletion-job';
    }

    public function __construct(Profile $profile)
    {
        $this->profile = $profile->wrapData();
    }

}
