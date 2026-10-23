<?php

namespace App\Tests\Service;

use App\Exception\InvalidArgumentException;
use App\Service\StripeAmount;
use PHPUnit\Framework\TestCase;

class StripeAmountTest extends TestCase
{
    /** @dataProvider minorUnitAmounts */
    public function testConvertsToMinorUnits(float|int|string $amount, string $currency, int $expected): void
    {
        $this->assertSame($expected, StripeAmount::toMinorUnits($amount, $currency));
    }

    public static function minorUnitAmounts(): array
    {
        return [
            'euro' => ['12.34', 'EUR', 1234],
            'euro rounding' => ['12.345', 'eur', 1235],
            'yen' => [60, 'JPY', 60],
            'won' => ['123', 'KRW', 123],
            'negative yen' => [-40, 'JPY', -40],
            'legacy isk' => [60, 'ISK', 6000],
            'legacy ugx' => [60, 'UGX', 6000],
        ];
    }

    /** @dataProvider majorUnitAmounts */
    public function testConvertsFromMinorUnits(int $amount, string $currency, float|int $expected): void
    {
        $this->assertSame($expected, StripeAmount::fromMinorUnits($amount, $currency));
    }

    public static function majorUnitAmounts(): array
    {
        return [
            'euro' => [1234, 'EUR', 12.34],
            'yen' => [60, 'JPY', 60],
            'negative yen' => [-40, 'JPY', -40],
            'legacy isk' => [6000, 'ISK', 60],
        ];
    }

    /** @dataProvider fractionalZeroDecimalAmounts */
    public function testRejectsFractionalZeroDecimalAmounts(string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        StripeAmount::toMinorUnits('12.34', $currency);
    }

    public static function fractionalZeroDecimalAmounts(): array
    {
        return [['JPY'], ['KRW'], ['ISK'], ['UGX']];
    }

    /** @dataProvider tinyFractionalWholeUnitAmounts */
    public function testRejectsTinyFractionalWholeUnitFloats(float $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('%s amounts must be whole currency units.', $currency));
        StripeAmount::toMinorUnits($amount, $currency);
    }

    public static function tinyFractionalWholeUnitAmounts(): array
    {
        return [
            'yen' => [1e-11, 'JPY'],
            'negative won' => [-1e-11, 'KRW'],
            'legacy isk' => [1e-11, 'ISK'],
            'legacy ugx' => [1e-11, 'UGX'],
        ];
    }

    public function testPayoutUsesCurrencySpecificWholeUnitRule(): void
    {
        $this->assertSame(6000, StripeAmount::toPayoutMinorUnits(60, 'HUF'));
        $this->assertSame(6000, StripeAmount::toPayoutMinorUnits('60.000', 'TWD'));
        $this->assertSame(60, StripeAmount::toPayoutMinorUnits(60, 'JPY'));
    }

    /** @dataProvider fractionalWholeUnitPayoutAmounts */
    public function testRejectsFractionalWholeUnitPayout(float|string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('%s payout amounts must be whole currency units.', $currency));
        StripeAmount::toPayoutMinorUnits($amount, $currency);
    }

    public static function fractionalWholeUnitPayoutAmounts(): array
    {
        return [
            'forint cents' => ['60.01', 'HUF'],
            'forint fraction below a cent' => ['60.001', 'HUF'],
            'taiwan dollar float below a cent' => [60.001, 'TWD'],
            'taiwan dollar tiny float' => [1e-11, 'TWD'],
        ];
    }

    public function testRejectsOverflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StripeAmount::toMinorUnits('9223372036854775808', 'JPY');
    }
}
