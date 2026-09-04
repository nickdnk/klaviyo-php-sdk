<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Template as SharedTemplate;

/**
 * @property string|null $name
 * @property string|null $editor_type
 * @property string|null $html
 * @property string|null $text
 * @property string|null $amp
 * @property string|null $created
 * @property string|null $updated
 * @property array|null  $definition   only with `additional-fields[template]=definition`
 */
class Template extends SharedTemplate
{

}
