<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\KlaviyoList as SharedKlaviyoList;

/**
 * @property string|null $name
 * @property string|null $created
 * @property string|null $updated
 * @property string|null $opt_in_process  double_opt_in|single_opt_in
 *
 * @property int|null $profile_count  only with additional-fields[list]=profile_count
 */
class KlaviyoList extends SharedKlaviyoList
{

}
