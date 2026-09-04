<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\TrackingSetting;

/**
 * PATCH /api/tracking-settings/{id}, where the id is the account id — an account holds
 * exactly one UTM tracking setting.
 *
 * Each `utm_*` attribute is keyed by the sending surface, `{flow, campaign}`, and every entry
 * is a `{type, value}` pair: `type` `static` takes any literal string, while `type` `dynamic`
 * takes a placeholder name Klaviyo resolves per send. The two surfaces accept different
 * placeholders — flows allow `email_subject`, `flow_id`, `flow_name`, `link_alt_text`,
 * `message_name`, `message_name_id`, `message_type`, `profile_external_id` and `profile_id`;
 * campaigns allow `campaign_id`, `campaign_name`, `campaign_name_id`,
 * `campaign_name_send_day`, `email_subject`, `group_id`, `group_name`, `group_name_id`,
 * `link_alt_text`, `message_type`, `profile_external_id`, `profile_id` and `variation_name`.
 *
 * `custom_parameters` is a list of the same `{flow, campaign}` shape plus a required `name`
 * that becomes the query-string key.
 *
 * @property bool|null  $auto_add_parameters
 * @property array|null $utm_source         {flow{type,value}, campaign{type,value}}
 * @property array|null $utm_medium         {flow{type,value}, campaign{type,value}}
 * @property array|null $utm_campaign       {flow{type,value}, campaign{type,value}}
 * @property array|null $utm_id             {flow{type,value}, campaign{type,value}}
 * @property array|null $utm_term           {flow{type,value}, campaign{type,value}}
 * @property array|null $custom_parameters  list of {name, flow{type,value}, campaign{type,value}}
 */
class UpdateTrackingSetting extends TrackingSetting
{

    public function __construct(string $id)
    {

        parent::__construct($id);
    }

}
