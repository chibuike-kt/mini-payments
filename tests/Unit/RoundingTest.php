<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Money\Rounding;

final class RoundingTest extends TestCase
{
  public function testDivFloor(): void
  {
    // 10/3 = 3 remainder 1 => floor returns 3
    self::assertSame(3, Rounding::div(10, 3, Rounding::FLOOR));

    // exact division stays exact
    self::assertSame(5, Rounding::div(10, 2, Rounding::FLOOR));
  }

  public function testDivCeil(): void
  {
    // 10/3 = 3.333... => ceil returns 4
    self::assertSame(4, Rounding::div(10, 3, Rounding::CEIL));

    // exact division stays exact
    self::assertSame(5, Rounding::div(10, 2, Rounding::CEIL));
  }

  public function testDivHalfUp(): void
  {
    // 10/4 = 2.5 => half_up rounds up to 3
    self::assertSame(3, Rounding::div(10, 4, Rounding::HALF_UP));

    // 9/4 = 2.25 => half_up stays 2
    self::assertSame(2, Rounding::div(9, 4, Rounding::HALF_UP));

    // 11/4 = 2.75 => half_up becomes 3
    self::assertSame(3, Rounding::div(11, 4, Rounding::HALF_UP));
  }

  public function testPercentBpsUsesIntegerMath(): void
  {
    // 1.5% of 500000 kobo => 7500 kobo
    self::assertSame(
      7500,
      Rounding::percentBps(500_000, 150, Rounding::FLOOR)
    );

    // 1% of 1 kobo = 0.01 kobo
    // floor -> 0, ceil -> 1
    self::assertSame(0, Rounding::percentBps(1, 100, Rounding::FLOOR));
    self::assertSame(1, Rounding::percentBps(1, 100, Rounding::CEIL));
  }

  public function testDivRejectsBadInputs(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Rounding::div(10, 0, Rounding::FLOOR);
  }

  public function testPercentRejectsNegative(): void
  {
    $this->expectException(InvalidArgumentException::class);
    Rounding::percentBps(-1, 100, Rounding::FLOOR);
  }
}
