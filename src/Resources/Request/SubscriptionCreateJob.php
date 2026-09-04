<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/profile-subscription-bulk-create-jobs. Records marketing consent for up to 1000
 * profiles and, when a list is given, adds them to it.
 *
 * Observed live: for an email Klaviyo has never seen, the job only creates the profile when a
 * list is given or `historicalImport` is true; a list-less, non-historical job for a new email
 * produces no profile at all (and there is no job-status endpoint to report it). Existing
 * profiles get their consent updated in every variant.
 *
 * @property array{data: SubscribeProfile[]} $profiles
 * @property bool|null                       $historical_import
 * @property string|null                     $custom_source
 */
class SubscriptionCreateJob extends TypedResource
{

    public static function type(): string
    {

        return 'profile-subscription-bulk-create-job';
    }

    /**
     * @param SubscribeProfile[] $profiles
     * @param string|null        $listId list to subscribe the profiles to (`relationships.list`); null = consent only
     */
    public function __construct(array $profiles, ?bool $historicalImport = null, ?string $customSource = null,
        ?string $listId = null
    )
    {

        $this->profiles = SubscribeProfile::wrapDataMany($profiles);
        $this->historical_import = $historicalImport;
        $this->custom_source = $customSource;
        if ($listId !== null) {
            $this->forList($listId);
        }
    }

    /**
     * Subscribes the profiles to `$listId` in addition to recording consent.
     */
    public function forList(string $listId): static
    {

        $this->addRelationship('list', new Relationship(new KlaviyoList($listId)));

        return $this;
    }

}
