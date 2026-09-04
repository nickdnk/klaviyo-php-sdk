<?php


namespace nickdnk\Klaviyo\Exceptions;

class JobTimeoutException extends BaseException
{

    public function __construct(string $message = 'Timed out waiting for Klaviyo job to complete.')
    {

        parent::__construct($message);
    }
}
