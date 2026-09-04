<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CampaignMessage as SharedCampaignMessage;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageContent;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageDefinition;
use nickdnk\Klaviyo\Resources\Shared\CampaignMessageRenderOptions;

/**
 * @property CampaignMessageDefinition|null $definition
 * @property array|null                     $send_times  list of {datetime, is_local} entries
 * @property string|null                    $created_at
 * @property string|null                    $updated_at
 *
 * Two shapes exist on the wire (observed 2026-09-04): `GET /api/campaign-messages/{id}` and campaign includes nest the
 * message under `definition`; `POST /api/campaign-message-assign-template` answers with the legacy flat shape
 * (`label`, `channel`, `content`, `render_options` at the top level, `definition` absent). Read `definition` first and fall
 * back to the flat properties when it is null.
 *
 * @property string|null                  $label            flat variant only
 * @property string|null                  $channel          flat variant only
 * @property CampaignMessageContent|null  $content          flat variant only
 * @property CampaignMessageRenderOptions|null $render_options flat variant only
 */
class CampaignMessage extends SharedCampaignMessage
{

    protected static function nested(): array
    {

        return [
            'definition'     => CampaignMessageDefinition::class,
            // flat variant returned by POST /api/campaign-message-assign-template
            'content'        => CampaignMessageContent::class,
            'render_options' => CampaignMessageRenderOptions::class,
        ];
    }

}
