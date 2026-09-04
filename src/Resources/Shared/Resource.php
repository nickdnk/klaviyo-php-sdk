<?php


namespace nickdnk\Klaviyo\Resources\Shared;

use ArrayAccess;
use JsonSerializable;

/**
 * Base resource for flat attribute objects (no JSON:API type/attributes envelope).
 *
 * @implements ArrayAccess<string, mixed>
 */
abstract class Resource implements JsonSerializable, ArrayAccess
{

    private array $data = [];

    public function __get(string $name): mixed
    {

        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {

        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {

        return isset($this->data[$name]);
    }

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {

        return isset($this->data[$offset]);
    }

    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {

        return $this->data[$offset] ?? null;
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {

        $this->data[$offset] = $value;
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {

        unset($this->data[$offset]);
    }

    #[\Override]
    public function jsonSerialize(): array
    {

        return self::filterNulls($this->data) ?? [];
    }

    /**
     * If a resource has children that are also resources, this array should return the key:value mapping.
     * We cannot use automatic hydration based on `type`, as not all hydratable objects have a type (i.e. they are not
     * all subclasses of `TypedResource`). A key whose value is a JSON list hydrates element-wise, so one mapping
     * serves both `{…}` and `[{…}, {…}]` attributes.
     *
     * @return array<string, class-string<Resource>>
     */
    protected static function nested(): array
    {

        return [];
    }

    public static function from(array $data, ?string $id = null): static
    {

        $instance = ($id !== null && is_subclass_of(static::class, IdentifiableResource::class)) ? new static($id)
            : new static();

        $nested = static::nested();

        foreach ($data as $key => $value) {
            if (is_array($value) && isset($nested[$key])) {
                $class = $nested[$key];
                $value = array_is_list($value)
                    ? array_map(fn($entry) => is_array($entry) ? $class::from($entry) : $entry, $value)
                    : $class::from($value);
            }
            // Written into the storage array directly rather than through `$instance->$key` or
            // ArrayAccess: a property write for an attribute literally named `data` would replace
            // the storage array itself, and hydration should not be subject to the write rules that
            // apply to callers (an identifier, for instance, is read-only once constructed).
            $instance->data[$key] = $value;
        }

        return $instance;
    }

    protected static function filterNulls(array $data): ?array
    {

        $filtered = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $value = self::filterNulls($value);
                if ($value === null) {
                    continue;
                }
            }
            $filtered[$key] = $value;
        }

        return $filtered ?: null;
    }

    public static function toISO8601(?int $timestamp): ?string
    {

        return $timestamp !== null ? date('c', $timestamp) : null;

    }

}
