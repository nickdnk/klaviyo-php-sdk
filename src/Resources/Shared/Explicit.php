<?php


namespace nickdnk\Klaviyo\Resources\Shared;

use JsonSerializable;

/**
 * Marker values that survive {@see Resource} serialisation. Request payloads drop `null`
 * attributes and empty arrays so that unset properties are not sent at all; several Klaviyo
 * PATCH endpoints, however, take an explicit `null` or `[]` to *clear* a value (tracking-setting
 * `utm_*` and `custom_parameters`, webhook `description`, campaign `audiences.excluded`,
 * mapped-metric relationships, …). Assign one of these instead:
 *
 * ```php
 * $update->description = Explicit::null();        // "description": null
 * $update->custom_parameters = Explicit::emptyList();   // "custom_parameters": []
 * $update->properties = Explicit::emptyObject();  // "properties": {}
 * ```
 */
final readonly class Explicit implements JsonSerializable
{

    private function __construct(private mixed $value) {}

    /** Serialises as JSON `null`. */
    public static function null(): self
    {

        return new self(null);

    }

    /** Serialises as JSON `[]`. */
    public static function emptyList(): self
    {

        return new self([]);

    }

    /** Serialises as JSON `{}`. */
    public static function emptyObject(): self
    {

        return new self((object)[]);

    }

    public function jsonSerialize(): mixed
    {

        return $this->value;

    }

}
