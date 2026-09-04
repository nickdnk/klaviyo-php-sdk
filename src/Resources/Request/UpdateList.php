<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;

/**
 * @property string|null $name
 * @property string|null $opt_in_process  double_opt_in|single_opt_in
 */
class UpdateList extends KlaviyoList
{

    public function __construct(string $id)
    {
        parent::__construct($id);
    }

}
