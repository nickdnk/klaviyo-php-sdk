<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateSourceMapping;
use nickdnk\Klaviyo\Resources\Response\SourceMapping;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Source mappings — how one data source's raw payload projects onto an object schema's
 * properties and linkages. A mapping is created with its schema
 * ({@see ObjectSchemaService::create()}) and reached from it through
 * {@see ObjectSchemaService::sourceMapping()}.
 */
class SourceMappingService extends BaseService
{

    use HasGet;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_source_mapping
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): SourceMapping|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_source_mapping
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateSourceMapping $sourceMapping, ?Query $query = null, bool $returnRequest = false): SourceMapping|RequestInterface
    {

        return $this->updateTrait($sourceMapping, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'source-mappings';
    }
}
