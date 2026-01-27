<?php

declare(strict_types=1);

namespace App\Fee;

use App\Money\Rounding;

final class FeePolicy
{
  // Choose rounding per fee type (this is the “policy” part)
  private const PLATFORM_FEE_ROUNDING = Rounding::FLOOR;
  private const PROVIDER_FEE_ROUNDING = Rounding::CEIL;
  private const VAT_ROUNDING = Rounding::FLOOR;

  // Basis points
  private const PLATFORM_FEE_BPS = 150;  // 1.5%
  private const PROVIDER_FEE_BPS = 100;  // 1.0%
  private const VAT_BPS = 750;           // 7.5%

  public static function platformFeeKobo(int $amountKobo): int
  {
    $fee = Rounding::percentBps($amountKobo, self::PLATFORM_FEE_BPS, self::PLATFORM_FEE_ROUNDING);

    // cap: 2000 NGN = 200,000 kobo
    $cap = 200_000;
    return min($fee, $cap);
  }

  public static function providerFeeKobo(int $amountKobo): int
  {
    return Rounding::percentBps($amountKobo, self::PROVIDER_FEE_BPS, self::PROVIDER_FEE_ROUNDING);
  }

  /**
   * VAT applied on platform fee only (demo policy).
   */
  public static function vatOnPlatformFeeKobo(int $platformFeeKobo): int
  {
    return Rounding::percentBps($platformFeeKobo, self::VAT_BPS, self::VAT_ROUNDING);
  }
}
