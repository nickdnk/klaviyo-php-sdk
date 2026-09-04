<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\FormVersion as SharedFormVersion;

/**
 * @property string|null       $form_type       banner|embed|flyout|full_page|popup
 * @property string|null       $variation_name
 * @property AttributeBag|null $ab_test         {variation_name}
 * @property string|null       $status          draft|live
 * @property string|null       $created_at
 * @property string|null       $updated_at
 */
class FormVersion extends SharedFormVersion
{

    protected static function nested(): array
    {

        return ['ab_test' => AttributeBag::class];
    }

}
