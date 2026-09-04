<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * Base for JSON:API resource objects that serialize as {type, attributes?, relationships?}.
 * Response hydration resolves `type` to a class through {@see \nickdnk\Klaviyo\Resources\TypeRegistry}.
 */
abstract class TypedResource extends Resource
{

    /**
     * The JSON:API `type` this resource serializes as and hydrates from.
     */
    abstract public static function type(): string;

    /** @var array<string, Relationship> */
    private array $relationships = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    #[\Override]
    public function jsonSerialize(): array
    {

        $attributes = parent::jsonSerialize();

        $object = ['type' => static::type()];
        if ($attributes) {
            $object['attributes'] = $attributes;
        }
        if ($this->relationships) {
            $object['relationships'] = $this->relationships;
        }

        $object = $this->withIdentity($object);

        if ($this->meta) {
            $object['meta'] = $this->meta;
        }

        return $object;
    }

    /**
     * Hook for subclasses that carry an id: appended after attributes / relationships and
     * before meta, which is the member order Klaviyo's own examples use.
     */
    protected function withIdentity(array $object): array
    {

        return $object;
    }

    /**
     * JSON:API `meta` on this resource object: request-side directives such as
     * `patch_properties` on a profile import, and response-side extras such as the
     * `relationship_id` Klaviyo attaches to object-schema linkage identifiers.
     */
    public function addMeta(string $name, mixed $value): static
    {

        $this->meta[$name] = $value;

        return $this;

    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {

        return $this->meta;

    }

    /**
     * Wraps this resource in a {data: ...} envelope.
     * @return array{data: static}
     */
    public function wrapData(): array
    {

        return ['data' => $this];

    }

    /**
     * Wraps an array of resources in a {data: [...]} envelope.
     * @param static[] $resources
     * @return array{data: static[]}
     */
    public static function wrapDataMany(array $resources): array
    {

        return ['data' => $resources];

    }

    /**
     * @return array<string,Relationship>
     */
    public function getRelationships(): array
    {

        return $this->relationships;

    }

    public function getRelationship(string $name): ?Relationship
    {

        return $this->relationships[$name] ?? null;

    }

    public function addRelationship(string $name, Relationship $relationship): void
    {

        $this->relationships[$name] = $relationship;

    }

}
