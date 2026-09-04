<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Review as SharedReview;

/**
 * @property string|null       $email
 * @property AttributeBag|null $status        {value, rejection_reason?{reason, status_explanation?}}
 * @property bool|null         $verified
 * @property string|null       $review_type   question|rating|review|store
 * @property string|null       $created
 * @property string|null       $updated
 * @property array|null        $images
 * @property AttributeBag|null $product       {url,name,image_url,external_id}
 * @property int|null          $rating
 * @property string|null       $author
 * @property string|null       $content
 * @property string|null       $title
 * @property string|null       $smart_quote
 * @property AttributeBag|null $public_reply  {content,author,updated}
 */
class Review extends SharedReview
{

    protected static function nested(): array
    {

        return [
            'status'       => AttributeBag::class,
            'product'      => AttributeBag::class,
            'public_reply' => AttributeBag::class,
        ];
    }

}
