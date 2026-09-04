<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\CatalogCategory;
use nickdnk\Klaviyo\Resources\Shared\CatalogItem;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/catalog-categories. `external_id` is the category's id in the source system;
 * Klaviyo builds the resource id from it by prefixing the integration and catalog type,
 * giving composite ids such as `$custom:::$default:::SAMPLE-DATA-CATEGORY-1`.
 *
 * Klaviyo accepts `$custom` as the integration type and `$default` as the catalog type.
 *
 * The same shape is embedded per category in {@see BulkCreateCatalogCategoriesJob}, which
 * creates up to 100 categories at a time.
 *
 * @property string      $external_id
 * @property string      $name
 * @property string      $integration_type
 * @property string|null $catalog_type
 */
class CreateCatalogCategory extends CatalogCategory
{

    /**
     * @param string[]|null $itemIds catalog item ids to place in the new category
     */
    public function __construct(string $externalId, string $name, string $integrationType = '$custom',
        ?string $catalogType = '$default', ?array $itemIds = null
    )
    {

        parent::__construct();
        $this->external_id = $externalId;
        $this->name = $name;
        $this->integration_type = $integrationType;
        $this->catalog_type = $catalogType;

        if ($itemIds) {
            $this->addRelationship(
                'items',
                new Relationship(array_map(fn(string $itemId) => new CatalogItem($itemId), array_values($itemIds)))
            );
        }
    }

}
