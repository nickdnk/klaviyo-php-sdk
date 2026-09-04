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

    #[\Override]
    protected function withIdentity(array $object): array
    {

        if ($this->id !== null) {
            $object['id'] = $this->id;
        }

        return $object;
    }

}
