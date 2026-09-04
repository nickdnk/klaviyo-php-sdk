<?php


namespace nickdnk\Klaviyo;

use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * Builds Klaviyo filter expressions with the value quoting and escaping the API expects, so
 * callers never concatenate user input into a filter string.
 *
 *   Filter::equals('email', $email)                       equals(email,"a@b.test")
 *   Filter::any('status', ['queued', 'processing'])       any(status,["queued","processing"])
 *   Filter::greaterThan('datetime', $since)               greater-than(datetime,2026-01-01T00:00:00+00:00)
 *   Filter::all(Filter::equals(…), Filter::lessThan(…))   equals(…),less-than(…)
 *
 * Values: strings are double-quoted with `"` and `\` backslash-escaped; ints, floats and
 * bools are literal; DateTimeInterface becomes an unquoted ISO 8601 timestamp; lists become
 * `[…]` of the same. Field names may contain letters, digits, `_ . - $`.
 *
 * @link https://developers.klaviyo.com/en/docs/filtering_
 */
final readonly class Filter implements Stringable
{

    private function __construct(private string $expression) {}

    public function __toString(): string
    {

        return $this->expression;

    }

    // region Comparison

    public static function equals(string $field, string|int|float|bool|DateTimeInterface $value): self
    {

        return self::op('equals', $field, $value);

    }

    public static function lessThan(string $field, int|float|DateTimeInterface|string $value): self
    {

        return self::op('less-than', $field, $value);

    }

    public static function lessOrEqual(string $field, int|float|DateTimeInterface|string $value): self
    {

        return self::op('less-or-equal', $field, $value);

    }

    public static function greaterThan(string $field, int|float|DateTimeInterface|string $value): self
    {

        return self::op('greater-than', $field, $value);

    }

    public static function greaterOrEqual(string $field, int|float|DateTimeInterface|string $value): self
    {

        return self::op('greater-or-equal', $field, $value);

    }

    // endregion

    // region String / list

    public static function contains(string $field, string $value): self
    {

        return self::op('contains', $field, $value);

    }

    /**
     * @param list<string|int|float|bool> $values
     */
    public static function containsAny(string $field, array $values): self
    {

        return self::op('contains-any', $field, $values);

    }

    /**
     * @param list<string|int|float|bool> $values
     */
    public static function containsAll(string $field, array $values): self
    {

        return self::op('contains-all', $field, $values);

    }

    public static function startsWith(string $field, string $value): self
    {

        return self::op('starts-with', $field, $value);

    }

    public static function endsWith(string $field, string $value): self
    {

        return self::op('ends-with', $field, $value);

    }

    /**
     * Field matches any of the values.
     *
     * @param list<string|int|float|bool> $values
     */
    public static function any(string $field, array $values): self
    {

        return self::op('any', $field, $values);

    }

    /**
     * Field is present / has a value.
     */
    public static function has(string $field): self
    {

        return new self('has(' . self::field($field) . ')');

    }

    // endregion

    /**
     * Combines expressions with the comma Klaviyo reads as AND.
     */
    public static function all(self ...$filters): self
    {

        if (!$filters) {
            throw new InvalidArgumentException('Filter::all() needs at least one filter.');
        }

        return new self(implode(',', array_map(strval(...), $filters)));

    }

    private static function op(string $operator, string $field, mixed $value): self
    {

        return new self($operator . '(' . self::field($field) . ',' . self::value($value) . ')');

    }

    private static function field(string $field): string
    {

        if (!preg_match('/^[A-Za-z0-9_.$\-]+$/', $field)) {
            throw new InvalidArgumentException(sprintf('Invalid Klaviyo filter field "%s".', $field));
        }

        return $field;

    }

    private static function value(mixed $value): string
    {

        return match (true) {
            is_string($value)                 => '"' . addcslashes($value, '"\\') . '"',
            is_bool($value)                   => $value ? 'true' : 'false',
            is_int($value) || is_float($value) => (string)$value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            is_array($value)                  => '[' . implode(',', array_map(self::value(...), array_values($value))) . ']',
            default                           => throw new InvalidArgumentException('Unsupported Klaviyo filter value type ' . get_debug_type($value) . '.'),
        };

    }

}
