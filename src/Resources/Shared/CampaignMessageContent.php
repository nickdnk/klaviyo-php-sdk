<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * `content` of a {@see CampaignMessageDefinition}. Which fields apply is decided by the
 * definition's channel: the address / subject fields are email, `body` alone is SMS, and
 * `title` / `dynamic_image` / `action_buttons` are mobile push.
 *
 * @property string|null $subject
 * @property string|null $preview_text
 * @property string|null $from_email
 * @property string|null $from_label
 * @property string|null $reply_to_email
 * @property string|null $cc_email
 * @property string|null $bcc_email
 * @property string|null $body
 * @property string|null $title
 * @property string|null $dynamic_image
 * @property array|null  $action_buttons
 */
class CampaignMessageContent extends Resource
{

}
