<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Image;

/**
 * POST /api/images: imports an image from a url or a data uri. Uploading the bytes
 * themselves goes through `POST /api/image-upload` instead
 * ({@see \nickdnk\Klaviyo\Services\ImageService::uploadFromFile()}).
 *
 * @property string      $import_from_url
 * @property string|null $name
 * @property bool|null   $hidden
 */
class UploadImageFromUrl extends Image
{

    public function __construct(string $importFromUrl, ?string $name = null, ?bool $hidden = null)
    {

        parent::__construct();
        $this->import_from_url = $importFromUrl;
        $this->name = $name;
        $this->hidden = $hidden;
    }

}
