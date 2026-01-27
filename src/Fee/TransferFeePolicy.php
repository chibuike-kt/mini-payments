<?php

declare(strict_types=1);

namespace App\Fee;

final class TransferFeePolicy
{
  // Simple demo: ₦10 flat fee
  public static function feeKobo(int $amountKobo): int
  {
    return 1_000; // 10 NGN
  }
}
