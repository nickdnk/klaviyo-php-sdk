<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\Segment;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/profile-suppression-bulk-create-jobs: manually suppresses profiles from email marketing.
 *
 * Profiles are identified by email only. Passing a list or segment id suppresses
 * every profile in it instead; Klaviyo accepts either profiles or exactly one of list / segment.
 *
 * @property array{data: Profile[]} $profiles
 */
class SuppressionCreateJob extends TypedResource
{

    /**
     * @param string[] $emails
     */
    public function __construct(array $emails = [], ?string $listId = null, ?string $segmentId = null)
    {

        $this->profiles = Profile::wrapDataMany(array_map(static function (string $email) {
            $profile = new Profile();
            $profile->email = $email;

            return $profile;
        }, array_values($emails)));

        if ($listId !== null) {
            $this->addRelationship('list', new Relationship(new KlaviyoList($listId)));
        }
        if ($segmentId !== null) {
            $this->addRelationship('segment', new Relationship(new Segment($segmentId)));
        }
    }

    public static function type(): string
    {

        return 'profile-suppression-bulk-create-job';
    }

}
