<?php


namespace nickdnk\Klaviyo\Resources\Shared;

readonly class PaginationLinks
{

    public function __construct(
        public string  $self,
        public ?string $first = null,
        public ?string $last = null,
        public ?string $prev = null,
        public ?string $next = null,
    ) {}

    public static function from(array $data): self
    {

        return new self(
            $data['self'],
            $data['first'] ?? null,
            $data['last'] ?? null,
            $data['prev'] ?? null,
            $data['next'] ?? null,
        );

    }

}
