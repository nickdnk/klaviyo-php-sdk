<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Segment;

/**
 * Updates a segment (PATCH /api/segments/{id}). Every attribute is optional; set only what
 * changes. A `definition` replaces the whole condition tree — see {@see CreateSegment} for
 * its shape.
 *
 * @property string|null $name
 * @property array{condition_groups: list<array{conditions: list<array<string, mixed>>}>}|null $definition
 * @property bool|null $is_starred
 * @property bool|null $is_active
 */
class UpdateSegment extends Segment
{

    public function __construct(string $id)
    {
        parent::__construct($id);
    }

}
