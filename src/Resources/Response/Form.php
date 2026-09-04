<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\Form as SharedForm;

/**
 * @property string|null       $name
 * @property string|null       $status      draft|live
 * @property bool|null         $ab_test
 * @property string|null       $created_at
 * @property string|null       $updated_at
 * @property AttributeBag|null $definition  {versions}
 */
class Form extends SharedForm
{

    protected static function nested(): array
    {

        return ['definition' => AttributeBag::class];
    }

}
