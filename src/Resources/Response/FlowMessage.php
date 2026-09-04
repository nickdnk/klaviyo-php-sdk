<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\FlowMessage as SharedFlowMessage;

/**
 * @property string|null                $channel
 * @property string|null                $created
 * @property string|null                $updated
 * @property FlowMessageDefinition|null $definition
 */
class FlowMessage extends SharedFlowMessage
{

    protected static function nested(): array
    {

        return ['definition' => FlowMessageDefinition::class];
    }
}
