<?php
declare(strict_types=1);

namespace Blissful\Support;

/**
 * Money is always an integer number of minor units (cents) plus an ISO-4217 code.
 * No floating point arithmetic is used anywhere in this class.
 */
final class Money
{
    /** minor-unit exponent per supported currency */
    public const EXPONENTS = ['USD' => 2, 'ZWG' => 2, 'ZAR' => 2];

    public static function isSupported(string $currency): bool
    {
        return isset(self::EXPONENTS[$currency]);
    }

    /** amount × basisPoints / 10000, rounded half-up, integers only. */
    public static function applyBps(int $amountMinor, int $bps): int
    {
        if ($amountMinor < 0 || $bps < 0) {
            throw new \InvalidArgumentException('Negative amounts are not allowed');
        }
        $product = $amountMinor * $bps;
        return intdiv($product + 5000, 10000);
    }

    /**
     * Price breakdown shown to the client before acceptance.
     * @return array{price_minor:int,extras_minor:int,subtotal_minor:int,fee_minor:int,total_minor:int,fee_bps:int,musician_receives_minor:int}
     */
    public static function breakdown(int $priceMinor, int $extrasMinor, int $feeBps): array
    {
        $subtotal = $priceMinor + $extrasMinor;
        $fee = self::applyBps($subtotal, $feeBps);
        return [
            'price_minor' => $priceMinor,
            'extras_minor' => $extrasMinor,
            'subtotal_minor' => $subtotal,
            'fee_bps' => $feeBps,
            'fee_minor' => $fee,
            'total_minor' => $subtotal + $fee,
            'musician_receives_minor' => $subtotal,
        ];
    }

    /** Parse "120", "120.5", "120.50" into minor units without floats. */
    public static function parseDecimal(string $value, string $currency): ?int
    {
        $exp = self::EXPONENTS[$currency] ?? null;
        if ($exp === null || !preg_match('/^\d{1,9}(\.\d{1,' . $exp . '})?$/', $value)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * (10 ** $exp) + (int) str_pad($frac, $exp, '0');
    }

    public static function format(int $minor, string $currency): string
    {
        $exp = self::EXPONENTS[$currency] ?? 2;
        $div = 10 ** $exp;
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        return sprintf('%s%s %s.%0' . $exp . 'd', $sign, $currency, number_format(intdiv($minor, $div)), $minor % $div);
    }
}
