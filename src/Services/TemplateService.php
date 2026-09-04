<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateTemplate;
use nickdnk\Klaviyo\Resources\Request\TemplateClone;
use nickdnk\Klaviyo\Resources\Request\TemplateRender;
use nickdnk\Klaviyo\Resources\Request\UpdateTemplate;
use nickdnk\Klaviyo\Resources\Response\Template;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Email templates, both hand-written HTML and drag-and-drop.
 *
 * - The block tree of a drag-and-drop template is left out by default: ask for it with
 *   `additional-fields[template]=definition`.
 * - HTML is normalised on write, so what you read back is not byte-identical to what you sent.
 * - A template that a flow send action copied answers 409 on delete, and keeps doing so after
 *   that flow is gone.
 *
 * @link https://developers.klaviyo.com/en/reference/templates_api_overview
 */
class TemplateService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasUpdate;

    private const string PATH_CLONE  = 'template-clone';
    private const string PATH_RENDER = 'template-render';

    /**
     * @link https://developers.klaviyo.com/en/reference/get_templates
     * @return array{data: Template[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_template
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Template|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * An account is capped at 1,000 API-created templates.
     *
     * @link https://developers.klaviyo.com/en/reference/create_template
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateTemplate $template, ?Query $query = null, bool $returnRequest = false): Template|RequestInterface
    {

        return $this->createTrait($template, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_template
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateTemplate $template, ?Query $query = null, bool $returnRequest = false): Template|RequestInterface
    {

        return $this->updateTrait($template, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/clone_template
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function clone(TemplateClone $clone, bool $returnRequest = false): Template|RequestInterface
    {

        $result = $this->request('POST', self::PATH_CLONE, $clone, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    /**
     * Renders the template against a context and returns a transient resource; nothing is stored.
     *
     * @link https://developers.klaviyo.com/en/reference/render_template
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function render(TemplateRender $render, bool $returnRequest = false): Template|RequestInterface
    {

        $result = $this->request('POST', self::PATH_RENDER, $render, returnRequest: $returnRequest);

        return $result instanceof RequestInterface ? $result : $result['data'];

    }

    protected function apiPath(): string
    {
        return 'templates';
    }
}
