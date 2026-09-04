<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\BackInStockSubscription;
use nickdnk\Klaviyo\Resources\Shared\CatalogVariant;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/back-in-stock-subscriptions: signs a profile up for the back in stock
 * notification on one catalog variant, over the given channels.
 *
 * The profile is matched or created from its identifiers, so an {@see ImportProfile} with at
 * least one of id / email / phone_number / external_id is required, and the channel picked
 * needs the matching identifier (`SMS` / `WHATSAPP` a phone number, `EMAIL` an email).
 *
 * @link https://developers.klaviyo.com/en/docs/how_to_set_up_custom_back_in_stock
 * @property string[]                    $channels  EMAIL|PUSH|SMS|WHATSAPP
 * @property array{data: ImportProfile}  $profile
 */
class CreateBackInStockSubscription extends BackInStockSubscription
{

    /**
     * @param string[] $channels
     */
    public function __construct(array $channels, ImportProfile $profile, string $variantId)
    {

        parent::__construct();
        $this->channels = array_values($channels);
        $this->profile = $profile->wrapData();
        $this->addRelationship('variant', new Relationship(new CatalogVariant($variantId)));

    }

}
