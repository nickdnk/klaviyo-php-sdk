<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\CampaignValuesReport as SharedCampaignValuesReport;

/**
 * One row per grouping combination. `groupings` echoes the applied `group_by` values and
 * `statistics` maps each requested statistic to its aggregate value.
 *
 * @property array<int, array{groupings: array<string, string>, statistics: array<string, int|float>}>|null $results
 */
class CampaignValuesReport extends SharedCampaignValuesReport
{

}
