<?php


namespace nickdnk\Klaviyo\Services;

use nickdnk\Klaviyo\Exceptions\ClientException;
use nickdnk\Klaviyo\Exceptions\ConnectionException;
use nickdnk\Klaviyo\Exceptions\OAuthException;
use nickdnk\Klaviyo\Exceptions\ServerException;
use nickdnk\Klaviyo\Query;
use nickdnk\Klaviyo\Resources\Request\UpdateReview;
use nickdnk\Klaviyo\Resources\Response\Review;
use nickdnk\Klaviyo\Resources\Shared\PaginationLinks;
use nickdnk\Klaviyo\Services\Traits\HasGet;
use nickdnk\Klaviyo\Services\Traits\HasList;
use nickdnk\Klaviyo\Services\Traits\HasUpdate;
use Psr\Http\Message\RequestInterface;

/**
 * Product reviews collected by Klaviyo Reviews.
 *
 * - Reviews are written by customers, so the API is read plus moderation:
 *   {@see self::update()} moves a review's `status` between pending, published, unpublished,
 *   featured and rejected.
 * - Both read endpoints accept `include=event` to embed the event the review arrived on.
 *
 * @link https://developers.klaviyo.com/en/reference/reviews_api_overview
 */
class ReviewService extends BaseService
{

    use HasGet;
    use HasList;
    use HasUpdate;

    /**
     * @link https://developers.klaviyo.com/en/reference/get_reviews
     * @return array{data: Review[], links: ?PaginationLinks}|RequestInterface
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
     * @link https://developers.klaviyo.com/en/reference/get_review
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function get(string $id, ?Query $query = null, bool $returnRequest = false): Review|RequestInterface|null
    {

        return $this->getTrait($id, $query, $returnRequest);

    }

    /**
     * @link https://developers.klaviyo.com/en/reference/update_review
     * @throws ClientException
     * @throws ConnectionException
     * @throws OAuthException
     * @throws ServerException
     */
    public function update(UpdateReview $review, ?Query $query = null, bool $returnRequest = false): Review|RequestInterface
    {

        return $this->updateTrait($review, $query, $returnRequest);

    }

    protected function apiPath(): string
    {

        return 'reviews';
    }
}
