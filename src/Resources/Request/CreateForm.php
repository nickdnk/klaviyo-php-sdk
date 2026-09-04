<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Form;

/**
 * POST /api/forms. A form is created with its full version tree in one call: `definition`
 * carries `versions`, and each version holds the rendered `steps` plus its layout, styles
 * and trigger configuration. Klaviyo only accepts `draft` here — publishing is a separate
 * step, so `status` has a single legal value.
 *
 * `definition` is passed through verbatim; its shape is
 * ```
 * ['versions' => [[
 *     'id'              => int|null,
 *     'steps'           => array[],   // required
 *     'triggers'        => array[],
 *     'teasers'         => array[],
 *     'dynamic_button'  => ['id' => string, 'type' => string, 'data' => array],
 *     'name'            => string|null,
 *     'styles'          => array,     // wrap_content, border_styles, close_button, margin,
 *                                     // padding, minimum_height, width, custom_width,
 *                                     // background_image, background_color, input_styles,
 *                                     // drop_shadow, overlay_color, rich_text_styles,
 *                                     // mobile_overlay, banner_styles, custom_css
 *     'properties'      => array,     // side_image_settings, click_outside_to_close,
 *                                     // rule_based_trigger_evaluation,
 *                                     // record_utm_params_on_submit, show_close_button,
 *                                     // accessible_name
 *     'type'            => string,    // banner|embed|flyout|full_screen|popup
 *     'location'        => string,    // bottom_center|bottom_left|bottom_right|center_left|
 *                                     // center_right|top_center|top_left|top_right
 *     'status'          => string,    // draft|live
 *     'ab_test'         => bool,
 *     'specialties'     => string[],
 *     'channel'         => string,    // IN_APP|WEB
 *     'message_priority'=> int,
 * ]]]
 * ```
 *
 * Rules Klaviyo enforces beyond the schema: every version needs at least two steps; any step
 * action with `submit: true` needs `properties.list_id`; no `id` members may be present on
 * create (string ids must be ULIDs); a column that has rows may not carry `styles`; `location`
 * is only valid when `type === 'flyout'`.
 *
 * @property string $name
 * @property array  $definition  {versions}
 * @property string $status      draft
 * @property bool   $ab_test
 */
class CreateForm extends Form
{

    public function __construct(string $name, array $definition, bool $abTest = false, string $status = 'draft')
    {

        parent::__construct();
        $this->name = $name;
        $this->definition = $definition;
        $this->status = $status;
        $this->ab_test = $abTest;
    }

}
