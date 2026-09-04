<?php


namespace nickdnk\Klaviyo\Exceptions;

use Throwable;

/**
 * The request never produced a response: DNS, connect, TLS or timeout failure in the
 * transport, after the retry policy gave up. `getPrevious()` is the PSR-18 exception.
 */
class ConnectionException extends BaseException
{

    public function __construct(Throwable $previous)
    {

        parent::__construct('Failed to connect to Klaviyo.', $previous);
    }
}
