<?php


namespace nickdnk\Klaviyo\Services;

use Generator;
use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Paginator;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateTrackingSetting;
use nickdnk\Klaviyo\Resources\Response\TrackingSetting;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * The account's UTM tracking settings: which `utm_*` parameters Klaviyo appends to the links in
 * campaign and flow sends.
 *
 * An account has exactly one setting, keyed by the account id, so {@see self::list()} answers a
 * single-entry collection and is how you discover that id.
 *
 * @link https://help.klaviyo.com/hc/en-us/articles/115005247808
 */
class TrackingSettingService extends BaseService
{

    use HasGet;
    use HasList;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_tracking_settings
     * @return array{data: TrackingSetting[], links: ?PaginationLinks}|RequestInterface  a single-entry list
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
     * Every TrackingSetting matching `$query`, fetching the next page only when the current one is exhausted;
     * breaking out of the loop stops the requests. `iterator_to_array()` it for the whole set, or use
     * {@see \nickdnk\Klaviyo\APIClient::paginate()} to see pages and links.
     *
     * @return Generator<int, TrackingSetting>
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
     * @link https://developers.klaviyo.com/en/reference/get_tracking_setting
     * @param string $id the account id
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): TrackingSetting|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_tracking_setting
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateTrackingSetting $setting, ?Query $query = null, bool $returnRequest = false): TrackingSetting|RequestInterface
    {

        return $this->updateTrait($setting, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'tracking-settings';
    }
}
