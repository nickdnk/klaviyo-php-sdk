<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\TemplateUniversalContent as SharedTemplateUniversalContent;

/**
 * @property string|null                     $name
 * @property UniversalContentDefinition|null $definition
 * @property string|null                     $created
 * @property string|null                     $updated
 * @property string|null                     $screenshot_status  completed|failed|generating|never_generated|not_renderable|stale
 * @property string|null                     $screenshot_url
 */
class TemplateUniversalContent extends SharedTemplateUniversalContent
{

    protected static function nested(): array
    {

        return ['definition' => UniversalContentDefinition::class];
    }
}
