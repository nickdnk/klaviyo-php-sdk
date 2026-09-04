<?php


namespace nickdnk\Klaviyo\Resources\Response;

use nickdnk\Klaviyo\Resources\Shared\ObjectIngestionLog as SharedObjectIngestionLog;

/**
 * @property string|null $status      error|info|warning
 * @property string|null $event_type  validation_error
 * @property string|null $timestamp
 * @property string|null $summary
 * @property array|null  $errors
 */
class ObjectIngestionLog extends SharedObjectIngestionLog
{

}
