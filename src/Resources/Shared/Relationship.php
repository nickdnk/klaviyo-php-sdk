<?php


namespace nickdnk\Klaviyo\Resources\Shared;

use JsonSerializable;

/**
 * A JSON:API relationship as hydrated from a response or attached to a request.
 *
 * `data` is an {@see IdentifiableResource} for to-one relations, a list for to-many relations
 * and null for an empty to-one relation. With `include=` the members carry full attributes;
 * otherwise they are identifiers with only `id` set. An empty list means the relation was
 * returned and is empty; {@see self::$hasData} is false when the API returned only `links`
 * (the related data was not inlined), so the two cases stay distinguishable.
 */
readonly class Relationship implements JsonSerializable
{

    /**
     * @param IdentifiableResource|IdentifiableResource[]|null $data
     * @param bool                                             $hasData false when the response carried no `data`
     *                                                                  member for this relationship (links only)
     */
    public function __construct(
        public IdentifiableResource|array|null $data,
        public ?RelationshipLinks              $links = null,
        public bool                            $hasData = true,
    ) {}

    /**
     * Ids of the related resources: one-element list for to-one, empty when null/empty.
     *
     * @return list<string>
     */
    public function ids(): array
    {

        if ($this->data === null) {
            return [];
        }
        $items = is_array($this->data) ? $this->data : [$this->data];

        return array_values(array_filter(array_map(static fn($r) => $r instanceof IdentifiableResource ? $r->id : ($r['id'] ?? null), $items), 'is_string'));

    }

    public function jsonSerialize(): array
    {

        $result = ['data' => $this->data];
        if ($this->links) {
            $result['links'] = $this->links;
        }

        return $result;

    }

}
