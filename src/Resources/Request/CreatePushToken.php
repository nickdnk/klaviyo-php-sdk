<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\DeviceMetadata;
use nickdnk\Klaviyo\Resources\Shared\PushToken;

/**
 * Registers a device token against a profile (POST /api/push-tokens). The profile is matched or
 * created from its identifiers, so an {@see ImportProfile} with at least one of id / email /
 * phone_number / external_id / anonymous_id is required.
 *
 * @property string                      $token
 * @property string                      $platform           android|ios
 * @property string                      $enablement_status  AUTHORIZED|DENIED|NOT_DETERMINED|PROVISIONAL|UNAUTHORIZED
 * @property string                      $vendor             apns|fcm
 * @property string|null                 $background         AVAILABLE|DENIED|RESTRICTED
 * @property DeviceMetadata|null         $device_metadata
 * @property array{data: ImportProfile}  $profile
 */
class CreatePushToken extends PushToken
{

    public function __construct(string $token, string $platform, string $enablementStatus, string $vendor,
        ImportProfile $profile, ?string $background = null, ?DeviceMetadata $deviceMetadata = null
    )
    {

        parent::__construct();
        $this->token = $token;
        $this->platform = $platform;
        $this->enablement_status = $enablementStatus;
        $this->vendor = $vendor;
        $this->profile = $profile->wrapData();
        $this->background = $background;
        $this->device_metadata = $deviceMetadata;
    }

}
