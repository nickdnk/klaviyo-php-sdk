<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\Flow as SharedFlow;

/**
 * @property string|null $name
 * @property string|null $status        draft|manual|live
 * @property bool|null   $archived
 * @property string|null $created
 * @property string|null $updated
 * @property string|null $trigger_type  Added to List|Date Based|Low Inventory|Metric|Price Drop|Unconfigured
 * @property array|null  $definition    only with `additional-fields[flow]=definition`
 */
class Flow extends SharedFlow
{

}
