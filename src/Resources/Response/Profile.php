<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Profile as SharedProfile;
use nickdnk\Klaviyo\Resources\Shared\ProfileLocation;

/**
 * @property string|null               $external_id
 * @property string|null               $first_name
 * @property string|null               $last_name
 * @property string|null               $organization
 * @property string|null               $locale
 * @property string|null               $title
 * @property string|null               $image
 * @property ProfileLocation|null      $location
 * @property array|null                $properties
 * @property string|null               $created
 * @property string|null               $updated
 * @property string|null               $last_event_date
 * @property SubscriptionChannels|null $subscriptions
 *
 * @property string|null       $anonymous_id
 * @property string|null       $whatsapp_bsuid     WhatsApp business-scoped user id
 * @property AttributeBag|null $predictive_analytics  only with additional-fields[profile]=predictive_analytics
 * @property string|null       $joined_group_at    only on list / segment membership listings (ISO 8601)
 */
class Profile extends SharedProfile
{

    protected static function nested(): array
    {

        return [
            'location'      => ProfileLocation::class,
            'subscriptions' => SubscriptionChannels::class,
        ];
    }
}
