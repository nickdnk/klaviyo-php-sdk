<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Image;

/**
 * PATCH /api/images/{id}. Only `name` and `hidden` are updatable; the bytes and the
 * hosted url are fixed once the image exists. Klaviyo applies the patch as a full replace of
 * these two: sending `hidden` alone resets `name` to null, so always send both.
 *
 * @property string|null $name
 * @property bool|null   $hidden
 */
class UpdateImage extends Image
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
