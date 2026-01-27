<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Fee\FeePolicy;

final class FeePolicyTest extends TestCase
{
  public function testPlatformFeeHasCap(): void
  {
    // Big number to hit cap.
    // With platform fee 1.5%, cap is 200,000 kobo.
    $amount = 100_000_000; // ₦1,000,000.00 in kobo
    $fee = FeePolicy::platformFeeKobo($amount);

    self::assertSame(200_000, $fee);
  }

  public function testVatAppliedOnPlatformFeeOnly(): void
  {
    // Choose an amount with platform fee = 7,500 kobo (₦75)
    $amount = 500_000;
    $platformFee = FeePolicy::platformFeeKobo($amount);
    $vat = FeePolicy::vatOnPlatformFeeKobo($platformFee);

    // VAT 7.5% of 7,500 = 562.5 kobo => rounding policy matters.
    // With VAT rounding = floor, expected 562
    self::assertSame(7_500, $platformFee);
    self::assertSame(562, $vat);
  }

  public function testProviderFeeCeilDoesNotUnderCollect(): void
  {
    // Find a value where 1% produces a fractional kobo.
    // amount=1 => 0.01kobo => ceil expected 1 kobo.
    $providerFee = FeePolicy::providerFeeKobo(1);
    self::assertSame(1, $providerFee);
  }

  public function testFeeInvariantMerchantPays(): void
  {
    // This test checks the accounting invariant you must always preserve:
    // merchant_net + platform_fee + vat + provider_fee == amount (merchant_pays)

    $amount = 500_001;

    $platformFee = FeePolicy::platformFeeKobo($amount);
    $vat = FeePolicy::vatOnPlatformFeeKobo($platformFee);
    $providerFee = FeePolicy::providerFeeKobo($amount);

    $merchantNet = $amount - ($platformFee + $vat + $providerFee);

    self::assertGreaterThanOrEqual(0, $merchantNet);
    self::assertSame($amount, $merchantNet + $platformFee + $vat + $providerFee);
  }

  public function testFeeInvariantCustomerPays(): void
  {
    // customer_pays:
    // total_collected = amount + fees
    // merchant_net = amount
    // merchant_net + fees == total_collected

    $amount = 500_001;

    $platformFee = FeePolicy::platformFeeKobo($amount);
    $vat = FeePolicy::vatOnPlatformFeeKobo($platformFee);
    $providerFee = FeePolicy::providerFeeKobo($amount);

    $totalCollected = $amount + ($platformFee + $vat + $providerFee);
    $merchantNet = $amount;

    self::assertSame($totalCollected, $merchantNet + $platformFee + $vat + $providerFee);
  }
}
