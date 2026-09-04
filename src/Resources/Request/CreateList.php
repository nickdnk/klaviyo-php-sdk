<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * @property string $name
 * @property string|null $opt_in_process  double_opt_in|single_opt_in
 */
class CreateList extends TypedResource
{
    public static function type(): string
    {

        return 'list';
    }

    public function __construct(string $name, string $optInProcess = 'single_opt_in')
    {
        $this->name = $name;
        $this->opt_in_process = $optInProcess;
    }

}
