<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\FlowAction as SharedFlowAction;

/**
 * @property string|null               $created
 * @property string|null               $updated
 * @property FlowActionDefinition|null $definition
 */
class FlowAction extends SharedFlowAction
{

    protected static function nested(): array
    {

        return ['definition' => FlowActionDefinition::class];
    }
}
