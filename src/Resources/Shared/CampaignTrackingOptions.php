<?php


namespace nickdnk\Klaviyo\Resources\Shared;

/**
 * Link and open tracking of a campaign. `is_tracking_clicks` / `is_tracking_opens` apply to
 * the email channel; SMS and mobile push carry the tracking-parameter fields only.
 *
 * @property bool|null  $add_tracking_params
 * @property array|null $custom_tracking_params  list of {type, value, name?} entries
 * @property bool|null  $is_tracking_clicks
 * @property bool|null  $is_tracking_opens
 */
class CampaignTrackingOptions extends Resource
{

}
