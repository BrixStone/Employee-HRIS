<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Payroll;

use App\Domain\Payroll\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_stores_centavos_exactly(): void
    {
        $m = Money::fromPesos(35000.00);
        $this->assertSame(3500000, $m->centavos());
    }

    public function test_it_splits_in_half_without_float_error(): void
    {
        $m = Money::fromPesos(35000.00);
        $half = $m->half();
        $this->assertSame(1750000, $half->centavos());
        $this->assertSame('17500.00', $half->format());
    }

    public function test_it_adds_two_money_instances(): void
    {
        $a = Money::fromPesos(17500.00);
        $b = Money::fromPesos(1500.00);
        $this->assertSame('19000.00', $a->add($b)->format());
    }

    public function test_it_subtracts_two_money_instances(): void
    {
        $a = Money::fromPesos(19000.00);
        $b = Money::fromPesos(855.00);
        $this->assertSame('18145.00', $a->subtract($b)->format());
    }

    public function test_it_multiplies_by_a_float_factor(): void
    {
        // daily rate × days worked
        $daily = Money::fromPesos(1136.36);
        $result = $daily->multiplyBy(10);
        $this->assertSame('11363.60', $result->format());
    }

    public function test_it_is_immutable(): void
    {
        $original = Money::fromPesos(35000.00);
        $original->half();
        // original must not change
        $this->assertSame('35000.00', $original->format());
    }
}