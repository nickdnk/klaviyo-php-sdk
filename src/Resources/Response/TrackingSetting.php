<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\TrackingSetting as SharedTrackingSetting;

/**
 * @property bool|null         $auto_add_parameters
 * @property AttributeBag|null $utm_source         {flow,campaign}
 * @property AttributeBag|null $utm_medium         {flow,campaign}
 * @property AttributeBag|null $utm_campaign       {flow,campaign}
 * @property AttributeBag|null $utm_id             {flow,campaign}
 * @property AttributeBag|null $utm_term           {flow,campaign}
 * @property array|null        $custom_parameters  list of {name, flow, campaign}
 */
class TrackingSetting extends SharedTrackingSetting
{

    protected static function nested(): array
    {

        return [
            'utm_source'   => AttributeBag::class,
            'utm_medium'   => AttributeBag::class,
            'utm_campaign' => AttributeBag::class,
            'utm_id'       => AttributeBag::class,
            'utm_term'     => AttributeBag::class,
        ];
    }

}
