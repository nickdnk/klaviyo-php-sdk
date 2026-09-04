<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * @property string|null $name
 * @property string|null $opt_in_process  double_opt_in|single_opt_in
 */
class KlaviyoList extends IdentifiableResource
{
    public static function type(): string
    {

        return 'list';
    }
}
