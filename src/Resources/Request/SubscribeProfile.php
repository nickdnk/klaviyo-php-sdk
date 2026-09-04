<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Profile;

/**
 * @property SubscriptionChannels $subscriptions
 * @property string|null $age_gated_date_of_birth  Required for SMS consent with age-gating enabled
 */
class SubscribeProfile extends Profile
{

    public function __construct(SubscriptionChannels $subscriptions, ?string $email = null, ?string $phoneNumber = null,
        ?string $id = null,
    )
    {
        parent::__construct($id);
        $this->email = $email;
        $this->phone_number = $phoneNumber;
        $this->subscriptions = $subscriptions;
    }

}
