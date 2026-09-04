<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList;
use nickdnk\Klaviyo\Resources\Shared\Relationship;
use nickdnk\Klaviyo\Resources\Shared\TypedResource;

/**
 * POST /api/profile-bulk-import-jobs. Upserts up to 10 000 profiles (5 MB) per job and, when
 * lists are given, adds every imported profile to them.
 *
 * @property array{data: ImportProfile[]} $profiles
 */
class BulkImportJob extends TypedResource
{
    public static function type(): string
    {

        return 'profile-bulk-import-job';
    }

    /**
     * @param ImportProfile[] $profiles
     * @param string[]        $listIds lists to add the imported profiles to (`relationships.lists`)
     */
    public function __construct(array $profiles, array $listIds = [])
    {
        $this->profiles = ImportProfile::wrapDataMany($profiles);
        if ($listIds) {
            $this->forLists(...$listIds);
        }
    }

    /**
     * Adds every imported profile to the given lists.
     */
    public function forLists(string ...$listIds): static
    {

        $this->addRelationship('lists', new Relationship(array_map(
            static fn(string $id) => new KlaviyoList($id),
            array_values($listIds),
        )));

        return $this;
    }

}
