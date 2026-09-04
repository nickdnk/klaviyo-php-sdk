<?php


namespace nickdnk\Klaviyo\Exceptions;

use Psr\Http\Message\ResponseInterface;

class ServerException extends BaseException
{

    public function __construct(private readonly ResponseInterface $response)
    {

        parent::__construct(sprintf('Klaviyo server error (HTTP %d)', $response->getStatusCode()));
    }

    public function getHttpStatus(): int
    {

        return $this->response->getStatusCode();
    }

    public function getResponse(): ResponseInterface
    {

        return $this->response;
    }
}
