<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Segment as SharedSegment;

/**
 * `profile_count` arrives only when requested through
 * `additional-fields[segment]=profile_count`.
 *
 * @property string|null $name
 * @property array{condition_groups: list<array{conditions: list<array<string, mixed>>}>}|null $definition
 * @property string|null $created
 * @property string|null $updated
 * @property bool|null   $is_active
 * @property bool|null   $is_processing
 * @property bool|null   $is_starred
 * @property int|null    $profile_count
 */
class Segment extends SharedSegment
{

}
