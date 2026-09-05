<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateForm;
use nickdnk\Klaviyo\Resources\Response\Form;
use nickdnk\Klaviyo\Resources\Response\FormVersion;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * Signup forms. A form is only the container: every rendered variation lives in a form version
 * ({@see FormVersionService}), which is where layout, styles and triggers are read.
 *
 * - There is no update endpoint. A form is created as a draft and published from the UI.
 * - A version needs at least two steps, `submit: true` actions require `properties.list_id`, and
 *   string ids inside a definition must be ULIDs.
 *
 * @link https://developers.klaviyo.com/en/reference/forms_api_overview
 */
class FormService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_forms
     * @return array{data: Form[], links: ?PaginationLinks}|RequestInterface
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
     * Every Form matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, Form>
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function iterate(?Query $query = null): Generator
    {
        return (new Paginator(fn(?string $next) => $this->list($query, $next)))->items();
    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_form
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Form|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/create_form
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateForm $form, ?Query $query = null, bool $returnRequest = false): Form|RequestInterface
    {

        return $this->createTrait($form, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_versions_for_form
     * @return array{data: FormVersion[], links: ?PaginationLinks}|RequestInterface
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function versions(string $formId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedTrait($formId, 'form-versions', $query, $next, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_version_ids_for_form
     * @return array{data: FormVersion[], links: ?PaginationLinks}|RequestInterface  each with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function versionIds(string $formId, ?Query $query = null, ?string $next = null, bool $returnRequest = false): array|RequestInterface
    {

        return $this->relatedIdsTrait($formId, 'form-versions', $query, $next, $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'forms';
    }
}
