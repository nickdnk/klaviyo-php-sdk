<?php


namespace nickdnk\Klaviyo\Exceptions;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A 4xx answer from the API. The JSON:API `errors` array is parsed into {@see KlaviyoError}
 * objects when present; the first error's `detail` becomes the message.
 */
class ClientException extends BaseException
{

    private int $httpStatus;

    /** @var list<array<string, mixed>>|null */
    private ?array $rawErrors = null;

    /** @var list<KlaviyoError> */
    private array $errors = [];

    public function __construct(private readonly RequestInterface $request, private readonly ResponseInterface $response)
    {

        $this->httpStatus = $response->getStatusCode();

        $json = json_decode((string)$response->getBody(), true);
        if (is_array($json) && is_array($json['errors'] ?? null)) {
            $this->rawErrors = array_values($json['errors']);
            $this->errors = array_map(
                static fn(array $e) => KlaviyoError::from($e),
                array_values(array_filter($this->rawErrors, 'is_array')),
            );
        }

        $first = $this->errors[0] ?? null;
        $msg = $first?->detail ?? $first?->title;

        parent::__construct(sprintf('Klaviyo client error (HTTP %d): %s', $this->httpStatus, $msg ?? 'Failed to parse response.'));
    }

    public function getHttpStatus(): int
    {

        return $this->httpStatus;
    }

    /**
     * The JSON:API `errors` entries as {@see KlaviyoError} objects. Empty when the body could
     * not be parsed or carried no `errors` array.
     *
     * @return list<KlaviyoError>
     */
    public function getErrors(): array
    {

        return $this->errors;
    }

    /**
     * First error, which is what the exception message is built from. Null when there is none.
     */
    public function getFirstError(): ?KlaviyoError
    {

        return $this->errors[0] ?? null;
    }

    /**
     * Errors whose `code` equals `$code`, e.g. `invalid` or `duplicate_profile`.
     *
     * @return list<KlaviyoError>
     */
    public function getErrorsWithCode(string $code): array
    {

        return array_values(array_filter($this->errors, static fn(KlaviyoError $e) => $e->code === $code));
    }

    /**
     * The `errors` array exactly as decoded, or null if the body couldn't be parsed or
     * didn't contain an `errors` array.
     *
     * @return list<array<string, mixed>>|null
     */
    public function getRawErrors(): ?array
    {

        return $this->rawErrors;
    }

    public function getRequest(): RequestInterface
    {

        return $this->request;
    }

    public function getResponse(): ResponseInterface
    {

        return $this->response;
    }
}
