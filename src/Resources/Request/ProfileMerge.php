<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;
use nickdnk\Klaviyo\Resources\Shared\Profile;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/profile-merge. The resource id is the surviving (destination) profile; the
 * `profiles` relationship lists the source profiles that are merged into it and deleted.
 * Klaviyo answers with the destination profile.
 */
class ProfileMerge extends IdentifiableResource
{

    /**
     * @param string   $destinationId Klaviyo id of the profile that survives
     * @param string[] $sourceIds     Klaviyo ids of the profiles merged into it
     */
    public function __construct(string $destinationId, array $sourceIds)
    {

        parent::__construct($destinationId);
        $this->addRelationship(
            'profiles',
            new Relationship(array_map(fn(string $id) => new Profile($id), array_values($sourceIds)))
        );
    }

    public static function type(): string
    {

        return 'profile-merge';
    }

}
