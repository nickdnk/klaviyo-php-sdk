<?php


namespace nickdnk\Klaviyo\Exceptions;

use Exception;
use Throwable;

abstract class BaseException extends Exception
{

    public function __construct(string $message, ?Throwable $previous = null)
    {

        parent::__construct($message, 0, $previous);
    }
}
