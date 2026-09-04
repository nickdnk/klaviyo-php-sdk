<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Response\Account;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use Psr\Http\Message\RequestInterface;

/**
 * The account behind the credentials: its name, contact details, timezone, currency and public
 * API key. Read-only, and a key only ever sees its own account, so {@see self::list()} answers a
 * single-entry collection and is a cheap way to check which account a key belongs to.
 *
 * This is one of Klaviyo's most tightly limited endpoints (1/s burst, 15/min), so keep it out of
 * wide concurrent pools.
 */
class AccountService extends BaseService
{

    use HasGet;
    use HasList;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_accounts
     * @return array{data: Account[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_account
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Account|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'accounts';
    }
}
