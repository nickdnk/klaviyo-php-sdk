<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\CreateBackInStockSubscription;
use nickdnk\Klaviyo\Services\Traits\HasCreate;
use Psr\Http\Message\RequestInterface;

/**
 * Back in stock notification signups, created against one catalog variant
 * ({@see CatalogVariantService}) because inventory is tracked per variant.
 *
 * - Write-only: Klaviyo exposes no endpoint for reading or cancelling existing subscriptions.
 * - Creating one enrols the profile in the account's back in stock flow; the notification itself
 *   is sent by that flow, not by this call.
 *
 * @link https://developers.klaviyo.com/en/reference/catalogs_api_overview
 */
class BackInStockSubscriptionService extends BaseService
{

    use HasCreate;

    /**
     * Klaviyo matches or creates the profile from the identifiers on the subscription.
     *
     * @link https://developers.klaviyo.com/en/reference/create_back_in_stock_subscription
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function create(CreateBackInStockSubscription $subscription, ?Query $query = null, bool $returnRequest = false): ?RequestInterface
    {

        return $this->createTrait($subscription, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'back-in-stock-subscriptions';

    }

}
