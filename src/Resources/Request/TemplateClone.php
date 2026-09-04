<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Template;

/**
 * POST /api/template-clone: copies the template the id points at. Omitting `$name` lets
 * Klaviyo derive one from the source template. An account is capped at 1,000 API-created
 * templates; past that the clone is rejected.
 *
 * @property string|null $name
 */
class TemplateClone extends Template
{

    /**
     * @param string $sourceId Klaviyo id of the template to copy
     */
    public function __construct(string $sourceId, ?string $name = null)
    {

        parent::__construct($sourceId);
        $this->name = $name;
    }

}
