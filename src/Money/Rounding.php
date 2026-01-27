<?php

declare(strict_types=1);

namespace App\Money;

final class Rounding
{
  public const FLOOR = 'floor';       // always down
  public const CEIL  = 'ceil';        // always up
  public const HALF_UP = 'half_up';   // .5 goes up

  /**
   * Divide integers with a rounding rule.
   *
   * We compute: numerator / denominator
   * but return an integer, using rounding mode.
   */
  public static function div(int $numerator, int $denominator, string $mode): int
  {
    if ($denominator <= 0) {
      throw new \InvalidArgumentException('denominator must be > 0');
    }

    if ($numerator < 0) {
      throw new \InvalidArgumentException('numerator must be >= 0');
    }

    $q = intdiv($numerator, $denominator);      // integer quotient
    $r = $numerator % $denominator;             // remainder

    if ($r === 0) {
      return $q; // divides perfectly, no rounding needed
    }

    if ($mode === self::FLOOR) {
      return $q;
    }

    if ($mode === self::CEIL) {
      return $q + 1;
    }

    if ($mode === self::HALF_UP) {
      // Compare remainder to half of denominator.
      // If remainder is >= half, round up.
      // We avoid floats: r*2 >= denominator.
      return ($r * 2 >= $denominator) ? ($q + 1) : $q;
    }

    throw new \InvalidArgumentException('unknown rounding mode: ' . $mode);
  }

  /**
   * Calculate percentage fee using basis points (bps), with rounding.
   * bps: 10000 = 100%
   */
  public static function percentBps(int $amountKobo, int $bps, string $mode): int
  {
    if ($amountKobo < 0) throw new \InvalidArgumentException('amountKobo must be >= 0');
    if ($bps < 0) throw new \InvalidArgumentException('bps must be >= 0');

    // numerator = amount * bps
    // denominator = 10000
    return self::div($amountKobo * $bps, 10_000, $mode);
  }
}
