<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\MultipartBody;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateImage;
use nickdnk\Klaviyo\Resources\Request\UploadImageFromUrl;
use nickdnk\Klaviyo\Resources\Response\Image;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The image library backing template and campaign content. Images arrive one of two ways:
 * {@see self::uploadFromUrl()} has Klaviyo fetch a url or data uri as JSON:API, while
 * {@see self::uploadFromFile()} posts the bytes to the separate `image-upload` endpoint as
 * `multipart/form-data`. Both answer with the stored image and its hosted `image_url`.
 */
class ImageService extends BaseService
{

    use HasCreate;
    use HasGet;
    use HasList;
    use HasUpdate;

    private const string PATH_UPLOAD = 'image-upload';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_images
     * @return array{data: Image[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function list(?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->listTrait($query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_image
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Image|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_image
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateImage $image, ?Query $query = null, bool $returnRequest = false): Image|RequestInterface
    {

        return $this->updateTrait($image, $query, $returnRequest);

    }

    /**
     * Klaviyo fetches the image itself from a url or data uri.
     *
     * @link https://developers.klaviyo.com/en/reference/upload_image_from_url
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function uploadFromUrl(UploadImageFromUrl $image, ?Query $query = null, bool $returnRequest = false): Image|RequestInterface
    {

        return $this->createTrait($image, $query, $returnRequest);

    }

    /**
     * POST /api/image-upload, the one Klaviyo endpoint that takes `multipart/form-data`
     * instead of JSON:API: the raw bytes go in the `file` part, `name` and `hidden` follow
     * as plain form fields (booleans as `true` / `false` strings).
     *
     * @link https://developers.klaviyo.com/en/reference/upload_image_from_file
     * @param string $contents raw image bytes
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function uploadFromFile(string $contents, string $filename, ?string $name = null, ?bool $hidden = null,
        bool $returnRequest = false
    ): Image|RequestInterface
    {

        $body = (new MultipartBody())->withFile('file', $contents, $filename);
        if ($name !== null) {
            $body = $body->withField('name', $name);
        }
        if ($hidden !== null) {
            $body = $body->withField('hidden', $hidden ? 'true' : 'false');
        }

        $result = $this->request('POST', self::PATH_UPLOAD, $body, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    protected function apiPath(): string
    {

        return 'images';

    }

}
