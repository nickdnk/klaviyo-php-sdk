<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/profile-subscription-bulk-delete-jobs. With a list, unsubscribes from that list
 * only; without one, unsubscribes the profiles account-wide.
 *
 * @property array{data: SubscribeProfile[]} $profiles
 */
class SubscriptionDeleteJob extends TypedResource
{
    public static function type(): string
    {

        return 'profile-subscription-bulk-delete-job';
    }

    /**
     * @param SubscribeProfile[] $profiles
     * @param string|null        $listId list to unsubscribe from (`relationships.list`); null = global unsubscribe
     */
    public function __construct(array $profiles, ?string $listId = null)
    {
        $this->profiles = SubscribeProfile::wrapDataMany($profiles);
        if ($listId !== null) {
            $this->forList($listId);
        }
    }

    /**
     * Scopes the unsubscribe to `$listId`.
     */
    public function forList(string $listId): static
    {

        $this->addRelationship('list', new Relationship(new KlaviyoList($listId)));

        return $this;
    }

}
