<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * The channel-specific body of a campaign message. `channel` picks which of the remaining
 * fields Klaviyo reads: `label` and the address fields are email, `render_options` is SMS,
 * and `kv_pairs` / `options` / `notification_type` are mobile push. A `silent` mobile push
 * carries `kv_pairs` only.
 *
 * @property string|null                       $channel            email|sms|mobile_push
 * @property string|null                       $label
 * @property CampaignMessageContent|null       $content
 * @property CampaignMessageRenderOptions|null $render_options
 * @property array|null                        $kv_pairs
 * @property array|null                        $options            {on_open, badge, play_sound}
 * @property string|null                       $notification_type  standard|silent
 */
class CampaignMessageDefinition extends Resource
{

    public function __construct(?string $channel = null)
    {

        $this->channel = $channel;
    }

    protected static function nested(): array
    {

        return [
            'content'        => CampaignMessageContent::class,
            'render_options' => CampaignMessageRenderOptions::class,
        ];
    }

}
