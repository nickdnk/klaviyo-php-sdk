<?php


namespace nickdnk\Klaviyo\Exceptions;

/**
 * One entry of the JSON:API `errors` array Klaviyo returns with a 4xx response.
 *
 * ```json
 * {
 *   "id": "360c1a11-cc3b-4f6c-b1a8-3cfbacd77c2f",
 *   "status": 400,
 *   "code": "invalid",
 *   "title": "Invalid input.",
 *   "detail": "Phone number is valid but is not in a supported region for this account.",
 *   "source": {"pointer": "/data/attributes/profiles/data/0/attributes/phone_number"},
 *   "links": {},
 *   "meta": {}
 * }
 * ```
 *
 * Every field is optional on the wire, so all are nullable or default to an empty array.
 * {@see self::$raw} keeps the untouched entry for anything not modelled here.
 *
 * @link https://developers.klaviyo.com/en/docs/errors_
 */
final readonly class KlaviyoError
{

    /**
     * @param string|null          $id        Unique identifier of this error occurrence.
     * @param int|null             $status    HTTP status this error carries; usually equals the response status.
     * @param string|null          $code      Machine-readable class, e.g. `invalid`, `not_found`, `duplicate_profile`.
     * @param string|null          $title     Short human-readable summary.
     * @param string|null          $detail    Specific explanation for this occurrence.
     * @param string|null          $pointer   JSON pointer into the request body (`source.pointer`).
     * @param string|null          $parameter Query parameter that caused the error (`source.parameter`).
     * @param array<string, mixed> $source    Full `source` object.
     * @param array<string, mixed> $meta      Full `meta` object, e.g. `duplicate_profile_id` on a 409.
     * @param array<string, mixed> $links     Full `links` object.
     * @param array<string, mixed> $raw       The error entry exactly as decoded.
     */
    public function __construct(
        public ?string $id,
        public ?int    $status,
        public ?string $code,
        public ?string $title,
        public ?string $detail,
        public ?string $pointer,
        public ?string $parameter,
        public array   $source,
        public array   $meta,
        public array   $links,
        public array   $raw,
    ) {}

    /**
     * @param array<string, mixed> $error one decoded entry of the `errors` array
     */
    public static function from(array $error): self
    {

        $source = is_array($error['source'] ?? null) ? $error['source'] : [];
        $status = $error['status'] ?? null;

        return new self(
            id:        self::string($error['id'] ?? null),
            status:    is_numeric($status) ? (int)$status : null,
            code:      self::string($error['code'] ?? null),
            title:     self::string($error['title'] ?? null),
            detail:    self::string($error['detail'] ?? null),
            pointer:   self::string($source['pointer'] ?? null),
            parameter: self::string($source['parameter'] ?? null),
            source:    $source,
            meta:      is_array($error['meta'] ?? null) ? $error['meta'] : [],
            links:     is_array($error['links'] ?? null) ? $error['links'] : [],
            raw:       $error,
        );

    }

    /**
     * Segments of {@see self::$pointer} without the leading slash, e.g.
     * `['data', 'attributes', 'profiles', 'data', '0', 'attributes', 'phone_number']`.
     * Empty when there is no pointer.
     *
     * @return list<string>
     */
    public function pointerSegments(): array
    {

        if ($this->pointer === null || $this->pointer === '' || $this->pointer === '/') {
            return [];
        }

        return array_values(array_filter(explode('/', ltrim($this->pointer, '/')), static fn(string $s) => $s !== ''));

    }

    /**
     * Array index the pointer refers to inside `$collectionPointer`, e.g. `0` for pointer
     * `/data/attributes/profiles/data/0/attributes/phone_number` and collection
     * `/data/attributes/profiles/data`. Null when the pointer is not below that collection or
     * the next segment is not an integer.
     */
    public function indexIn(string $collectionPointer): ?int
    {

        $prefix = rtrim($collectionPointer, '/') . '/';
        if ($this->pointer === null || !str_starts_with($this->pointer, $prefix)) {
            return null;
        }

        $next = explode('/', substr($this->pointer, strlen($prefix)), 2)[0];

        return ctype_digit($next) ? (int)$next : null;

    }

    public function __toString(): string
    {

        $parts = array_filter([
            $this->status !== null ? (string)$this->status : null,
            $this->code,
            $this->detail ?? $this->title,
            $this->pointer !== null ? "at {$this->pointer}" : null,
        ]);

        return implode(' ', $parts);

    }

    private static function string(mixed $v): ?string
    {

        return is_scalar($v) ? (string)$v : null;

    }

}
