<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\Flow;

/**
 * POST /api/flows: creates a flow from an encoded flow definition. Objects that do not exist
 * yet are identified inside the definition by `temporary_id`; Klaviyo swaps those for real
 * `id` fields and echoes the stored definition back on the created resource.
 *
 * `$definition` mirrors the request body verbatim:
 *
 *   'triggers'         (required) list of trigger objects, each discriminated by `type`:
 *                      ['type' => 'list'|'metric'|…, 'id' => '…', 'trigger_filter' => [...]],
 *                      date-based    ['type' => …, 'date_field_type' => …, 'date_profile_property' => …,
 *                                     'timedelta_unit_before_date' => …, 'timedelta_value_before_date' => 0,
 *                                     'recurrence_frequency' => …, 'timezone' => …, 'trigger_time' => …,
 *                                     'trigger_days' => [...]],
 *                      custom object ['type' => …, 'date_field_type' => …, 'custom_object_label' => …,
 *                                     'custom_object_property_id' => 0, 'object_type_id' => …,
 *                                     'object_type_relationship_id' => …, …],
 *                      price drop    ['type' => …, 'trigger_filter' => [...], 'price_drop_amount_value' => …,
 *                                     'price_drop_amount_unit' => …, 'audience' => [...],
 *                                     'timeframe_days' => 0, 'currency_type' => …],
 *                      low inventory ['type' => …, 'product_level' => …, 'trigger_filter' => [...],
 *                                     'inventory_count' => 0, 'audience' => [...], 'timeframe_days' => 0]
 *   'profile_filter'   ['condition_groups' => [['conditions' => [...]], …]]
 *   'actions'          (required) list of action objects, each
 *                      ['id' => '…', 'temporary_id' => '…', 'type' => '…', 'links' => [...], 'data' => [...]];
 *                      see {@see UpdateFlowAction} for the per-type `links` / `data` shapes
 *   'entry_action_id'  (required) id or temporary_id of the first action, or null
 *   'reentry_criteria' ['duration' => 0, 'unit' => 'day'|'hour'|'week'|'alltime']
 *
 * Null leaves are dropped from the serialized body, so a terminal action's `links.next` and an
 * empty `entry_action_id` travel as absent keys, which Klaviyo reads the same as null.
 *
 * @property string $name
 * @property array  $definition
 */
class CreateFlow extends Flow
{

    public function __construct(string $name, array $definition)
    {

        parent::__construct();
        $this->name = $name;
        $this->definition = $definition;
    }

}
