<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Template;

/**
 * POST /api/templates: a new HTML or drag-and-drop template. An account is capped at 1,000
 * API-created templates; past that Klaviyo rejects the request.
 *
 * `html` carries the markup of a code template; `definition` carries the block tree of a
 * drag-and-drop one:
 *
 *   'id'          '…'|null
 *   'template_id' '…'|null
 *   'body'        (required) ['id' => …, 'properties' => ['id' => …, 'css_class' => …],
 *                             'styles' => ['background_color' => …, 'width' => 0],
 *                             'sections' => [['id' => …, 'data_id' => …, 'universal_id' => …,
 *                                             'content_type' => …, 'type' => …, 'data' => [...],
 *                                             'rows' => [...]], …]]
 *   'styles'      (required) [['id' => …, 'style_type' => …, 'properties' => [...], 'styles' => [...]], …]
 *
 * @property string      $name
 * @property string      $editor_type
 * @property string|null $html
 * @property string|null $text
 * @property string|null $amp
 * @property array|null  $definition
 */
class CreateTemplate extends Template
{

    public function __construct(string $name, string $editorType, ?string $html = null, ?string $text = null,
        ?string $amp = null
    )
    {

        parent::__construct();
        $this->name = $name;
        $this->editor_type = $editorType;
        $this->html = $html;
        $this->text = $text;
        $this->amp = $amp;
    }

}
