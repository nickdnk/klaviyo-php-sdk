<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * Generic, concrete resource for free-form attribute maps (e.g. Klaviyo event_properties)
 * whose keys vary by topic. Inherits null-safe property and array access from {@see Resource},
 * so missing keys return null instead of raising "Undefined array key" warnings.
 */
class AttributeBag extends Resource
{

}
