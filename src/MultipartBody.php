<?php


namespace nickdnk\Klaviyo;

use Random\RandomException;

/**
 * Body for the few Klaviyo endpoints that take `multipart/form-data` instead of JSON:API
 * (`POST /api/image-upload`). Parts carry `name`, `contents`, optional `filename` and
 * `headers`. Keep `contents` a string so the request can be rebuilt for the 401-refresh retry.
 *
 * @link https://www.rfc-editor.org/rfc/rfc7578
 */
final readonly class MultipartBody
{

    /**
     * @param list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}> $parts
     */
    public function __construct(public array $parts = []) {}

    public function withField(string $name, string $value): self
    {

        return new self([...$this->parts, ['name' => $name, 'contents' => $value]]);

    }

    public function withFile(string $name, string $contents, string $filename, ?string $contentType = null): self
    {

        $part = ['name' => $name, 'contents' => $contents, 'filename' => $filename];
        if ($contentType !== null) {
            $part['headers'] = ['Content-Type' => $contentType];
        }

        return new self([...$this->parts, $part]);

    }

    public static function boundary(): string
    {

        return bin2hex(random_bytes(20));

    }

    /**
     * RFC 7578 encoding of the parts with the given boundary. Pair with a
     * `Content-Type: multipart/form-data; boundary=…` header carrying the same value.
     */
    public function encode(string $boundary): string
    {

        $out = '';
        foreach ($this->parts as $part) {
            $disposition = 'form-data; name="' . self::quote($part['name']) . '"';
            if (isset($part['filename'])) {
                $disposition .= '; filename="' . self::quote($part['filename']) . '"';
            }

            $out .= '--' . $boundary . "\r\n";
            $out .= 'Content-Disposition: ' . $disposition . "\r\n";
            foreach ($part['headers'] ?? [] as $header => $value) {
                $out .= $header . ': ' . $value . "\r\n";
            }
            $out .= "\r\n" . $part['contents'] . "\r\n";
        }

        return $out . '--' . $boundary . "--\r\n";

    }

    private static function quote(string $value): string
    {

        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '%0D', '%0A'], $value);

    }

}
