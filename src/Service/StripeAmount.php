<?php

namespace App\Service;

use App\Exception\InvalidArgumentException;

final class StripeAmount
{
    // ISK and UGX use two-decimal API amounts for backwards compatibility.
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public static function toMinorUnits(float|int|string $amount, string $currency): int
    {
        $currency = strtolower($currency);
        if (!preg_match('/^[a-z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Invalid Stripe currency.');
        }
        $scale = in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
        $matches = self::parseAmount($amount, $currency);
        $fraction = $matches[3] ?? '';
        $requiresWholeUnits = $scale === 0 || in_array($currency, ['isk', 'ugx'], true);
        if ($requiresWholeUnits && ((is_float($amount) && floor($amount) !== $amount) || trim($fraction, '0') !== '')) {
            throw new InvalidArgumentException(sprintf('%s amounts must be whole currency units.', strtoupper($currency)));
        }

        $fraction = str_pad($fraction, $scale + 1, '0');
        $digits = $matches[2].substr($fraction, 0, $scale);
        $minor = gmp_init($digits, 10);
        if ($fraction[$scale] >= '5') {
            $minor = gmp_add($minor, 1);
        }

        if (gmp_cmp($minor, PHP_INT_MAX) > 0) {
            throw new InvalidArgumentException('Stripe amount exceeds the supported integer range.');
        }

        $result = gmp_intval($minor);

        return $matches[1] === '-' ? -$result : $result;
    }

    public static function fromMinorUnits(int $amount, string $currency): float|int
    {
        $scale = in_array(strtolower($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;

        return $scale === 0 ? $amount : $amount / 100;
    }

    public static function toPayoutMinorUnits(float|int|string $amount, string $currency): int
    {
        $currency = strtolower($currency);
        if (in_array($currency, ['huf', 'twd'], true)) {
            $matches = self::parseAmount($amount, $currency);
            if ((is_float($amount) && floor($amount) !== $amount) || trim($matches[3] ?? '', '0') !== '') {
                throw new InvalidArgumentException(sprintf('%s payout amounts must be whole currency units.', strtoupper($currency)));
            }
        }

        return self::toMinorUnits($amount, $currency);
    }

    private static function parseAmount(float|int|string $amount, string $currency): array
    {
        $value = (string) $amount;

        // PHP can stringify a large or very small float in scientific notation.
        if (is_float($amount) && strpbrk($value, 'eE') !== false) {
            $value = rtrim(rtrim(sprintf('%.10F', $amount), '0'), '.');
        }

        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches)) {
            throw new InvalidArgumentException(sprintf('Invalid %s amount: %s', $currency, $value));
        }

        return $matches;
    }
}
