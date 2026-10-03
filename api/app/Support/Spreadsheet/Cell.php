<?php

namespace App\Support\Spreadsheet;

/**
 * A single spreadsheet cell: a value plus the style slot it renders with.
 *
 * Values stay native (string / int / float) so Excel receives real numbers and
 * can total them, rather than text that merely looks numeric.
 */
final readonly class Cell
{
    public const STYLE_DEFAULT = 0;

    public const STYLE_HEADER = 1;

    public const STYLE_MONEY = 2;

    public const STYLE_INTEGER = 3;

    public const STYLE_BOLD = 4;

    public const STYLE_TITLE = 5;

    public const STYLE_DATE = 6;

    public const STYLE_PERCENT = 7;

    public const STYLE_BOLD_MONEY = 8;

    /** Bold integer — used for totals rows that must stay summable. */
    public const STYLE_BOLD_INTEGER = 9;

    /** Excel's day zero, as a serial date. */
    private const SERIAL_EPOCH = '1899-12-30';

    private function __construct(
        public string|int|float|null $value,
        public int $style,
    ) {}

    public static function text(string|int|float|null $value): self
    {
        return new self($value === null ? null : (string) $value, self::STYLE_DEFAULT);
    }

    /**
     * Bold text. Numeric values stay numeric so a bolded total is still a number
     * Excel can sum — a bolded "3" must not become the string "3".
     */
    public static function bold(string|int|float|null $value): self
    {
        if (is_int($value) || is_float($value)) {
            return new self($value + 0, self::STYLE_BOLD_INTEGER);
        }

        return new self($value === null ? null : (string) $value, self::STYLE_BOLD);
    }

    public static function header(string $value): self
    {
        return new self($value, self::STYLE_HEADER);
    }

    public static function title(string $value): self
    {
        return new self($value, self::STYLE_TITLE);
    }

    /**
     * A real number, kept numeric so Excel can sum and pivot it.
     */
    public static function number(int|float|null $value): self
    {
        return new self($value === null ? null : $value + 0, self::STYLE_INTEGER);
    }

    public static function money(int|float|null $value): self
    {
        return new self($value === null ? null : $value + 0, self::STYLE_MONEY);
    }

    public static function boldMoney(int|float|null $value): self
    {
        return new self($value === null ? null : $value + 0, self::STYLE_BOLD_MONEY);
    }

    /**
     * A percentage already expressed as a percentage (12.5 renders as "12.50%").
     */
    public static function percent(int|float|null $value): self
    {
        return new self($value === null ? null : $value + 0, self::STYLE_PERCENT);
    }

    /**
     * A calendar date given as `Y-m-d`, stored as an Excel serial number so it
     * sorts and filters as a date rather than as text.
     */
    public static function date(?string $value): self
    {
        if ($value === null || $value === '') {
            return new self(null, self::STYLE_DATE);
        }

        return new self(self::toSerial($value), self::STYLE_DATE);
    }

    public function isNumeric(): bool
    {
        return is_int($this->value) || is_float($this->value);
    }

    public function isEmpty(): bool
    {
        return $this->value === null || $this->value === '';
    }

    private static function toSerial(string $date): ?float
    {
        try {
            $epoch = new \DateTimeImmutable(self::SERIAL_EPOCH.' 00:00:00', new \DateTimeZone('UTC'));
            $target = new \DateTimeImmutable(substr($date, 0, 10).' 00:00:00', new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return ($target->getTimestamp() - $epoch->getTimestamp()) / 86400;
    }
}
