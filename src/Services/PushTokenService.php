<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreatePushToken;
use nickdnk\Klaviyo\Resources\Response\Profile;
use nickdnk\Klaviyo\Resources\Response\PushToken;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use nickdnk\Klaviyo\Services\Traits\HasDelete;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasRelationships;
use Psr\Http\Message\RequestInterface;

/**
 * Push tokens are the device registrations a profile can be reached at, also listed by
 * {@see ProfileService::pushTokens()}.
 *
 * - Tokens are meant to be registered by Klaviyo's iOS and Android SDKs; {@see self::create()}
 *   exists to migrate tokens from another platform and needs the push entitlement, answering 403
 *   without it.
 * - There is no update endpoint: create the token again to change its state.
 *
 * @link https://developers.klaviyo.com/en/reference/profiles_api_overview
 */
class PushTokenService extends BaseService
{

    use HasCreate;
    use HasDelete;
    use HasGet;
    use HasList;
    use HasRelationships;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_push_tokens
     * @return array{data: PushToken[], links: ?PaginationLinks}|RequestInterface
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
     * Every PushToken matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, PushToken>
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
     * @link https://developers.klaviyo.com/en/reference/get_push_token
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): PushToken|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * Klaviyo answers 202 with an empty body; the token is fetched later via {@see self::list()}.
     *
     * @link https://developers.klaviyo.com/en/reference/create_push_token
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreatePushToken $token, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->createTrait($token, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_for_push_token
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profile(string $tokenId, ?Query $query = null, bool $returnRequest = false): Profile|RequestInterface|null
    {

        return $this->relatedOneTrait($tokenId, 'profile', $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/get_profile_id_for_push_token
     * @return Profile|RequestInterface|null  with only `id` set
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function profileId(string $tokenId, bool $returnRequest = false): Profile|RequestInterface|null
    {

        return $this->relatedOneIdTrait($tokenId, 'profile', $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'push-tokens';
    }
}
