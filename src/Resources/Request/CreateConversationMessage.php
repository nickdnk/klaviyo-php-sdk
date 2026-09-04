<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Conversation;
use nickdnk\Klaviyo\Resources\Shared\ConversationMessage;
use nickdnk\Klaviyo\Resources\Shared\Relationship;

/**
 * POST /api/conversation-messages: sends an outbound message into an existing conversation,
 * which the required `conversation` relationship selects. `body` is the plain-text fallback
 * every channel can render.
 *
 * `message_hierarchy` is an ordered list of channel-specific renderings, tried best-first, so
 * an RCS-capable handset gets rich content and everyone else falls back to SMS. Each entry is
 * one of:
 * ```
 * ['message' => string, 'message_format' => 'RCS', 'extra' => array,
 *  'rich_content' => ['cards' => array[], 'suggestions' => array[], 'file' => array],
 *  'image' => ['static_image_asset_id' => int, 'dynamic_image_template' => string,
 *              'rendered_asset_url' => string]]
 * ```
 * ```
 * ['message' => string, 'message_format' => 'SMS', 'extra' => array,
 *  'image' => array, 'media' => array]
 * ```
 * where the SMS variant's `message` is required.
 *
 * Requires account-level enablement of the conversations API.
 *
 * @property string     $body
 * @property array|null $message_hierarchy  ordered list of channel renderings
 */
class CreateConversationMessage extends ConversationMessage
{

    public function __construct(string $body, string $conversationId, ?array $messageHierarchy = null)
    {

        parent::__construct();
        $this->body = $body;
        $this->message_hierarchy = $messageHierarchy;
        $this->addRelationship('conversation', new Relationship(new Conversation($conversationId)));
    }

}
