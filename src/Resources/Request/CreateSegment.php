<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Segment;

/**
 * Creates a segment (POST /api/segments).
 *
 * `definition` is Klaviyo's segment-condition tree, passed through verbatim as a plain
 * array: `{condition_groups: [{conditions: [ … ]}]}`. Groups are OR-ed, the conditions
 * inside a group are AND-ed, and each condition is one of Klaviyo's condition objects
 * (`profile-marketing-consent`, `profile-metric`, `profile-group-membership`, …), keyed by
 * its own `type` plus that type's operands.
 *
 * @property string $name
 * @property array{condition_groups: list<array{conditions: list<array<string, mixed>>}>} $definition
 * @property bool|null $is_starred
 */
class CreateSegment extends Segment
{

    /**
     * @param array{condition_groups: list<array{conditions: list<array<string, mixed>>}>} $definition
     */
    public function __construct(string $name, array $definition)
    {
        parent::__construct();
        $this->name = $name;
        $this->definition = $definition;
    }

}
