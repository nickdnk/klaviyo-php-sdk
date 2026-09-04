<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property MarketingConsent $marketing
 * @property array<array{
 *     token: string,
 *     platform: 'android'|'ios',
 *     vendor: 'apns'|'fcm',
 *     enablement_status?: 'AUTHORIZED'|'DENIED'|'NOT_DETERMINED'|'PROVISIONAL'|'UNAUTHORIZED',
 *     background?: 'AVAILABLE'|'DENIED'|'RESTRICTED',
 *     device_metadata?: array{
 *         device_id?: string,
 *         klaviyo_sdk?: 'android'|'flutter'|'flutter_community'|'react_native'|'swift',
 *         sdk_version?: string,
 *         device_model?: string,
 *         os_name?: 'android'|'ios'|'ipados'|'macos'|'tvos',
 *         os_version?: string,
 *         manufacturer?: string,
 *         app_name?: string,
 *         app_version?: string,
 *         app_build?: string,
 *         app_id?: string,
 *         environment?: 'debug'|'release',
 *     },
 * }>|null $tokens
 * @property string|null $anonymous_id
 */
class PushSubscription extends Resource
{

    public function __construct(MarketingConsent $marketing)
    {
        $this->marketing = $marketing;
    }

}
