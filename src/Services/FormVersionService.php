<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Response\Form;
use nickdnk\Klaviyo\Resources\Response\FormVersion;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * One rendered variation of a signup form. Versions are read-only and are listed from their
 * parent form via {@see FormService::versions()}; the relationship back to that form is
 * to-one.
 */
class FormVersionService extends BaseService
{

    use HasGet;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_form_version
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): FormVersion|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    // region Relationships

    /**
     * @link https://developers.klaviyo.com/en/reference/get_form_for_form_version
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function form(string $versionId, ?Query $query = null, bool $returnRequest = false): Form|RequestInterface|null
    {

        return $this->relatedOneTrait($versionId, 'form', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_form_id_for_form_version
     * @return Form|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function formId(string $versionId, bool $returnRequest = false): Form|RequestInterface|null
    {

        return $this->relatedOneIdTrait($versionId, 'form', $returnRequest);

    }

    // endregion

    protected function apiPath(): string
    {

        return 'form-versions';
    }
}
