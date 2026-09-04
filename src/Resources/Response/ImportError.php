<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\AttributeBag;
use nickdnk\Klaviyo\Resources\Shared\IdentifiableResource;

/**
 * One rejected row of a profile bulk import job.
 *
 * @property string            $code
 * @property string            $title
 * @property string            $detail
 * @property AttributeBag      $source            {pointer: JSON pointer into the submitted payload}
 * @property AttributeBag|null $original_payload  the submitted profile as Klaviyo saw it
 */
class ImportError extends IdentifiableResource
{

    public static function type(): string
    {

        return 'import-error';
    }

    protected static function nested(): array
    {

        return [
            'source'           => AttributeBag::class,
            'original_payload' => AttributeBag::class,
        ];
    }
}
