<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * `links` of a relationship object. Klaviyo usually sends both `self` and `related`, but some
 * responses (e.g. the `profiles` relationship on a bulk import job) carry only `related`, so
 * both are nullable.
 */
readonly class RelationshipLinks
{

    public function __construct(
        public ?string $self = null,
        public ?string $related = null,
    ) {}

    public static function from(array $data): self
    {

        return new self(
            isset($data['self']) ? (string)$data['self'] : null,
            isset($data['related']) ? (string)$data['related'] : null,
        );

    }

}
