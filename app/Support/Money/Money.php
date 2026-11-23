<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value stored as integer minor units (e.g. dirhams for QAR).
 * Never use floats for money anywhere in the codebase.
 */
final class Money implements JsonSerializable
{
    /** Decimal places per ISO currency; default 2. */
    private const DECIMALS = ['KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'JPY' => 0];

    private function __construct(public readonly int $amount, public readonly string $currency) {}

    public static function of(int $minor, string $currency = 'QAR'): self
    {
        return new self($minor, strtoupper($currency));
    }

    public static function zero(string $currency = 'QAR'): self
    {
        return new self(0, strtoupper($currency));
    }

    /** Build from a human decimal string/number, e.g. "149.50" QAR -> 14950. */
    public static function fromDecimal(string|int|float $value, string $currency = 'QAR'): self
    {
        $decimals = self::decimals($currency);
        $normalized = number_format((float) $value, $decimals, '.', '');

        return new self((int) str_replace('.', '', $normalized), strtoupper($currency));
    }

    public static function decimals(string $currency): int
    {
        return self::DECIMALS[strtoupper($currency)] ?? 2;
    }

    public function plus(self|int $other): self
    {
        return new self($this->amount + $this->minorOf($other), $this->currency);
    }

    public function minus(self|int $other): self
    {
        return new self($this->amount - $this->minorOf($other), $this->currency);
    }

    public function times(int|float $factor): self
    {
        return new self((int) round($this->amount * $factor, 0, PHP_ROUND_HALF_UP), $this->currency);
    }

    /** Percentage of this amount, rounded half-up to the minor unit. */
    public function percent(int|float $percent): self
    {
        return $this->times($percent / 100);
    }

    /**
     * Split into $parts amounts that always add back up to the original (no lost dirham).
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be >= 1');
        }
        $base = intdiv($this->amount, $parts);
        $remainder = $this->amount - $base * $parts;
        $out = [];
        for ($i = 0; $i < $parts; $i++) {
            $out[] = new self($base + ($i < $remainder ? 1 : 0), $this->currency);
        }

        return $out;
    }

    public function max(self|int $other): self
    {
        return new self(max($this->amount, $this->minorOf($other)), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function toDecimal(): string
    {
        $d = self::decimals($this->currency);

        return number_format($this->amount / (10 ** $d), $d, '.', '');
    }

    public function format(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $d = self::decimals($this->currency);
        $number = number_format($this->amount / (10 ** $d), $d, '.', ',');

        return $locale === 'ar'
            ? $number.' '.($this->currency === 'QAR' ? 'ر.ق' : $this->currency)
            : $this->currency.' '.$number;
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function minorOf(self|int $other): int
    {
        if ($other instanceof self) {
            if ($other->currency !== $this->currency) {
                throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
            }

            return $other->amount;
        }

        return $other;
    }
}
