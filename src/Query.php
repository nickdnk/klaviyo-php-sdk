<?php


namespace nickdnk\Klaviyo;

use InvalidArgumentException;

/**
 * Query-string parameters shared by Klaviyo's JSON:API collection and single-resource
 * endpoints: sparse fieldsets, additional fields, includes, filter, sort and cursor
 * pagination. Build fluently, then pass to a service `list()` / `get()` call; the service
 * serialises it with {@see self::toArray()}.
 *
 * Which parameters an endpoint accepts varies (single-resource GETs ignore filter / sort /
 * page); Klaviyo rejects unsupported ones with a 400, so only set what the endpoint documents.
 *
 * @link https://developers.klaviyo.com/en/docs/filtering_
 * @link https://developers.klaviyo.com/en/docs/sparse_fieldsets
 * @link https://developers.klaviyo.com/en/docs/relationships_#includes
 */
final class Query
{

    private ?string $filter = null;

    /** @var string[] */
    private array $include = [];

    /** @var array<string, string[]> type => field names */
    private array $fields = [];

    /** @var array<string, string[]> type => field names */
    private array $additionalFields = [];

    private ?string $sort = null;

    private ?int $pageSize = null;

    private ?string $cursor = null;

    /**
     * Filter expression, preferably built with {@see Filter} so values are quoted and escaped;
     * a raw string such as `equals(email,"a@b.test")` is accepted as-is. Replaces any
     * previously set filter.
     */
    public function filter(Filter|string|null $filter): self
    {

        $this->filter = $filter === null ? null : (string)$filter;

        return $this;

    }

    /**
     * Relationship names to embed in the response (`include=a,b`). Accumulates across calls.
     */
    public function include(string ...$relations): self
    {

        $this->include = array_values(array_unique([...$this->include, ...$relations]));

        return $this;

    }

    /**
     * Sparse fieldset for one resource type (`fields[type]=a,b`). Accumulates per type.
     */
    public function fields(string $type, string ...$fields): self
    {

        $this->fields[$type] = array_values(array_unique([...($this->fields[$type] ?? []), ...$fields]));

        return $this;

    }

    /**
     * Fields that are excluded by default and must be requested explicitly
     * (`additional-fields[type]=a,b`), e.g. `subscriptions` on profiles. Accumulates per type.
     */
    public function additionalFields(string $type, string ...$fields): self
    {

        $this->additionalFields[$type] = array_values(
            array_unique([...($this->additionalFields[$type] ?? []), ...$fields])
        );

        return $this;

    }

    /**
     * Sort field. Prefixes `-` for descending. Replaces any previously set sort.
     */
    public function sort(string $field, bool $descending = false): self
    {

        $this->sort = ($descending ? '-' : '') . ltrim($field, '-');

        return $this;

    }

    public function pageSize(?int $size): self
    {

        $this->pageSize = $size;

        return $this;

    }

    /**
     * Cursor for the next page. Accepts either the bare `page[cursor]` value or a full
     * `links.next` URL as Klaviyo returns it, from which the cursor is extracted; the other
     * parameters on that URL are the ones this query already carries.
     *
     * @throws InvalidArgumentException when a URL is given that carries no `page[cursor]`
     */
    public function cursor(?string $cursor): self
    {

        if ($cursor !== null && str_starts_with($cursor, 'http')) {
            parse_str((string)parse_url($cursor, PHP_URL_QUERY), $params);
            $cursor = $params['page']['cursor'] ?? throw new InvalidArgumentException(
                'The pagination URL carries no page[cursor] parameter: ' . $cursor
            );
        }

        $this->cursor = $cursor;

        return $this;

    }

    /**
     * Guzzle-ready query array. Empty when nothing is set, so callers can pass
     * `$query->toArray() ?: null` straight through.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {

        $out = [];

        foreach ($this->fields as $type => $fields) {
            if ($fields) {
                $out['fields[' . $type . ']'] = implode(',', $fields);
            }
        }

        foreach ($this->additionalFields as $type => $fields) {
            if ($fields) {
                $out['additional-fields[' . $type . ']'] = implode(',', $fields);
            }
        }

        if ($this->filter !== null && $this->filter !== '') {
            $out['filter'] = $this->filter;
        }

        if ($this->include) {
            $out['include'] = implode(',', $this->include);
        }

        if ($this->sort !== null) {
            $out['sort'] = $this->sort;
        }

        if ($this->pageSize !== null) {
            $out['page[size]'] = $this->pageSize;
        }

        if ($this->cursor !== null && $this->cursor !== '') {
            $out['page[cursor]'] = $this->cursor;
        }

        return $out;

    }

}
