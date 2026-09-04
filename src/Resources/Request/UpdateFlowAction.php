<?php


namespace nickdnk\Klaviyo\Resources\Request;

use nickdnk\Klaviyo\Resources\Shared\FlowAction;

/**
 * PATCH /api/flow-actions/{id}: replaces the action's definition. The definition is a union
 * discriminated by `type`; an action's live/draft/manual/disabled state travels inside
 * `data.status`, so a status change is a definition write like any other.
 *
 * `$definition` shape:
 *
 *   'id'           Klaviyo id of the action, or null
 *   'temporary_id' placeholder id for an action that does not exist yet, or null
 *   'type'         (required) action-output-split, back-in-stock-delay, conditional-split,
 *                  content-experiment, send-email, send-mobile-push, send-sms, send-webhook,
 *                  send-internal-alert, send-whatsapp, send-whatsapp-with-ac, time-delay,
 *                  trigger-split, update-profile, target-date, countdown-delay, ab-test,
 *                  internal-service, code, multi-branch-split or list-update
 *   'links'        ['next' => '…'|null] for linear actions, or
 *                  ['next_if_true' => '…'|null, 'next_if_false' => '…'|null] for the split types
 *   'data'         per-type payload, e.g.
 *                  send-email        ['message' => ['subject_line' => …, 'template_id' => …, …],
 *                                     'status' => 'live']
 *                  send-sms          ['message' => ['body' => …, …], 'status' => 'live']
 *                  send-webhook      ['message' => ['url' => …, 'headers' => [...], 'body' => …]]
 *                  time-delay        ['unit' => 'days'|'hours'|'minutes', 'value' => 0,
 *                                     'secondary_value' => null, 'timezone' => 'UTC'|'profile'|…,
 *                                     'delay_until_time' => null, 'delay_until_weekdays' => [...]]
 *                  conditional-split ['profile_filter' => ['condition_groups' => [...]]]
 *                  trigger-split     ['trigger_filter' => ['condition_groups' => [...]],
 *                                     'trigger_id' => …, 'trigger_type' => …, 'trigger_subtype' => …]
 *                  list-update       ['name' => …, 'on_execution' => true, 'list_id' => …,
 *                                     'status' => 'live']
 *
 * Null leaves are dropped from the serialized body, so a terminal action's `links.next` travels
 * as an absent key, which Klaviyo reads the same as null.
 *
 * @property array $definition
 */
class UpdateFlowAction extends FlowAction
{

    public function __construct(string $id, array $definition)
    {

        parent::__construct($id);
        $this->definition = $definition;
    }

}
