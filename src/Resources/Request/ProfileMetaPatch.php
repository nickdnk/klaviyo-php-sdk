<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * @property array|null $append  Key-value pairs to append to property arrays
 * @property array|null $unappend  Key-value pairs to remove from property arrays
 * @property string|string[]|null $unset  Property name(s) to remove completely
 */
class ProfileMetaPatch extends Resource
{

    public function __construct(?array $append = null, ?array $unappend = null, string|array|null $unset = null)
    {
        $this->append = $append;
        $this->unappend = $unappend;
        $this->unset = $unset;
    }

}
