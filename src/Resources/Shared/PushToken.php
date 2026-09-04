<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $token
 * @property string|null $platform           android|ios
 * @property string|null $enablement_status  AUTHORIZED|DENIED|NOT_DETERMINED|PROVISIONAL|UNAUTHORIZED
 * @property string|null $vendor             apns|fcm
 * @property string|null $background         AVAILABLE|DENIED|RESTRICTED
 */
class PushToken extends IdentifiableResource
{
    public static function type(): string
    {

        return 'push-token';
    }
}
