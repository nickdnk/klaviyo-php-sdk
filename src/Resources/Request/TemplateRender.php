<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Template;

/**
 * POST /api/template-render: renders the template the id points at against `$context` and
 * answers with its AMP, HTML and plain-text output. Templates render like Django templates,
 * so `$context` is the variable map the markup references, nested objects included, e.g.
 * `['first_name' => 'Ada', 'event' => ['name' => 'Opening night']]`.
 *
 * @property array $context
 */
class TemplateRender extends Template
{

    public function __construct(string $id, array $context)
    {

        parent::__construct($id);
        $this->context = $context;
    }

}
