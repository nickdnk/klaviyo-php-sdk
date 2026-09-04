<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateUniversalContent;
use nickdnk\Klaviyo\Resources\Request\UpdateUniversalContent;
use nickdnk\Klaviyo\Resources\Response\TemplateUniversalContent;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Saved template blocks that templates embed by id, one block per resource. Supported block
 * types are button, drop_shadow, horizontal_rule, html, image, spacer and text.
 *
 * `delete()` (DELETE /api/template-universal-content/{id}, delete_universal_content) comes
 * from {@see HasDelete}.
 */
class UniversalContentService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_all_universal_content
     * @return array{data: TemplateUniversalContent[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_universal_content
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): TemplateUniversalContent|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_universal_content
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateUniversalContent $content, ?Query $query = null, bool $returnRequest = false): TemplateUniversalContent|RequestInterface
    {

        return $this->createTrait($content, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_universal_content
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateUniversalContent $content, ?Query $query = null, bool $returnRequest = false): TemplateUniversalContent|RequestInterface
    {

        return $this->updateTrait($content, $query, $returnRequest);

    }

    protected function apiPath(): string
    {
        return 'template-universal-content';
    }
}
