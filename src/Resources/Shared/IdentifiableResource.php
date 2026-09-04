<?php


namespace nickdnk\Klaviyo\Resources\Shared;

abstract class IdentifiableResource extends TypedResource
{

    public function __construct(public readonly ?string $id = null) {}

    public function __isset(string $name): bool
    {
        if ($name === 'id') {
            return $this->id !== null;
        }

        return parent::__isset($name);
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        if ($offset === 'id') {
            return $this->id !== null;
        }

        return parent::offsetExists($offset);
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        if ($offset === 'id') {
            return $this->id;
        }

        return parent::offsetGet($offset);
    }

    /**
     * The identifier is fixed when the resource is constructed and read through `$resource->id`;
     * it is not one of the attributes this ArrayAccess covers. To address a different resource,
     * create a new instance with that id.
     *
     * @throws \LogicException when `$offset` is `id`
     */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'id') {
            throw new \LogicException('The id of a ' . static::class . ' is read-only; construct a new instance to change it.');
        }

        parent::offsetSet($offset, $value);
    }

    #[\Override]
    protected function withIdentity(array $object): array
    {

        if ($this->id !== null) {
            $object['id'] = $this->id;
        }

        return $object;
    }

}
