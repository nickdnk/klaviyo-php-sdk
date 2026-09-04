<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * Device fields attached to a push token, both on create requests and in responses.
 *
 * @property string|null $device_id
 * @property string|null $klaviyo_sdk   android|flutter|flutter_community|react_native|swift
 * @property string|null $sdk_version
 * @property string|null $device_model
 * @property string|null $os_name       android|ios|ipados|macos|tvos
 * @property string|null $os_version
 * @property string|null $manufacturer
 * @property string|null $app_name
 * @property string|null $app_version
 * @property string|null $app_build
 * @property string|null $app_id
 * @property string|null $environment   debug|release
 */
class DeviceMetadata extends Resource
{

}
