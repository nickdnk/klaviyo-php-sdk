<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\Shared\ProfileLocation;

/**
 * Shared base for profile create / patch payloads. Subclasses differ only in whether the
 * Klaviyo profile id is present:
 *  - `CreateProfile`: no id (`POST /api/profiles`) - no meta.
 *  - `PatchProfile`: required id (`PATCH /api/profiles/{id}`) - has meta.
 *  - `ImportProfile`: optional id (`POST /api/profile-import` and `POST /api/profile-bulk-import-jobs`) - has meta.
 *
 * @property string|null          $external_id
 * @property string|null          $_kx
 * @property string|null          $anonymous_id
 * @property string|null          $first_name
 * @property string|null          $last_name
 * @property string|null          $organization
 * @property string|null          $locale
 * @property string|null          $title
 * @property string|null          $image
 * @property ProfileLocation|null $location
 * @property array|null           $properties
 */
abstract class ProfileRequest extends Profile
{


}
