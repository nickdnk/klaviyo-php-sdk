<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Resource;

/**
 * One flow message's stored definition as `GET /api/flow-messages/{id}` returns it: the spec's
 * `oneOf` of `FlowEmail`, `FlowInternalAlert`, `FlowPushNotification`, `FlowSms`, `FlowWebhook`,
 * `FlowWhatsApp` and `FlowWhatsAppWithAC` — a flat, channel-specific payload, not the
 * `channel / content / *_options` envelope used when creating a flow. Which keys are present
 * depends on the channel (`FlowMessage::$channel`); the others read back as null.
 *
 * Common:         `id`, `name`
 * Email:          `from_email`, `from_label`, `reply_to_email`, `cc_email`, `bcc_email`, `subject_line`, `preview_text`,
 *                 `template_id`, `smart_sending_enabled`, `transactional`, `add_tracking_params`, `custom_tracking_params`,
 *                 `additional_filters`
 * Internal alert: `from_email`, `from_label`, `to_emails`, `subject_line`, `template_id`
 * SMS:            `body`, `image_id`, `dynamic_image`, `message_hierarchy`, `shorten_links`, `include_contact_card`,
 *                 `add_org_prefix`, `add_info_link`, `add_opt_out_language`, `smart_sending_enabled`,
 *                 `sms_quiet_hours_enabled`, `transactional`, `add_tracking_params`, `custom_tracking_params`, `template_id`,
 *                 `additional_filters`
 * Push:           `title`, `body`, `sound`, `badge`, `badge_options`, `image_id`, `dynamic_image`, `video_asset_id`, `on_open`,
 *                 `ios_link`, `android_link`, `web_url`, `push_type`, `kv_pairs`, `conversion_metric_id`,
 *                 `smart_sending_enabled`, `additional_filters`, `action_buttons`
 * Webhook:        `url`, `headers`, `body`
 * WhatsApp:       `vendor_id`, `smart_sending_enabled`, `transactional`, `add_tracking_params`, `additional_filters`,
 *                 `automation_id`, `expiry_hours`
 *
 * @property string|null       $id
 * @property string|null       $name
 * @property string|null       $from_email
 * @property string|null       $from_label
 * @property string|null       $reply_to_email
 * @property string|null       $cc_email
 * @property string|null       $bcc_email
 * @property string[]|null     $to_emails
 * @property string|null       $subject_line
 * @property string|null       $preview_text
 * @property string|null       $template_id
 * @property string|null       $body
 * @property string|null       $title
 * @property string|null       $sound
 * @property int|null          $badge
 * @property AttributeBag|null $badge_options
 * @property string|null       $image_id
 * @property AttributeBag|null $dynamic_image
 * @property string|null       $video_asset_id
 * @property AttributeBag|null $on_open
 * @property string|null       $ios_link
 * @property string|null       $android_link
 * @property string|null       $web_url
 * @property string|null       $push_type
 * @property array|null        $kv_pairs
 * @property string|null       $conversion_metric_id
 * @property array|null        $action_buttons
 * @property string|null       $message_hierarchy
 * @property bool|null         $shorten_links
 * @property bool|null         $include_contact_card
 * @property bool|null         $add_org_prefix
 * @property bool|null         $add_info_link
 * @property bool|null         $add_opt_out_language
 * @property bool|null         $sms_quiet_hours_enabled
 * @property bool|null         $smart_sending_enabled
 * @property bool|null         $transactional
 * @property bool|null         $add_tracking_params
 * @property array|null        $custom_tracking_params
 * @property AttributeBag|null $additional_filters
 * @property string|null       $url
 * @property array|null        $headers
 * @property string|null       $vendor_id
 * @property string|null       $automation_id
 * @property int|null          $expiry_hours
 */
class FlowMessageDefinition extends Resource
{

    protected static function nested(): array
    {

        return [
            'additional_filters' => AttributeBag::class,
            'on_open'            => AttributeBag::class,
            'badge_options'      => AttributeBag::class,
            'dynamic_image'      => AttributeBag::class,
        ];
    }
}
