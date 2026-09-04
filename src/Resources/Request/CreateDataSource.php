<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\DataSource;

/**
 * POST /api/data-sources: the upstream feed object records are ingested from.
 *
 * @property string      $title
 * @property string|null $visibility   private|shared
 * @property string|null $description
 * @property string|null $namespace
 */
class CreateDataSource extends DataSource
{

    public function __construct(string $title, ?string $visibility = null, ?string $description = null,
        ?string $namespace = null
    )
    {

        parent::__construct();
        $this->title = $title;
        $this->visibility = $visibility;
        $this->description = $description;
        $this->namespace = $namespace;
    }

}
