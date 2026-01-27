<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Db\Db;
use App\Db\Migrate;
use App\Ledger\Ledger;
use App\App;

final class TransferFailedDebitedVariant1Test extends TestCase
{
  private PDO $pdo;
  private App $app;

  protected function setUp(): void
  {
    $this->pdo = Db::pdo(':memory:');
    Migrate::run($this->pdo, __DIR__ . '/../../database/schema_v2.sql');
    Migrate::run($this->pdo, __DIR__ . '/../../database/schema_transfers.sql');
    $this->app = new App($this->pdo);
  }

  public function testFailedDebitedDoesNotRefundUntilReversed(): void
  {
    // Create user
    $u = $this->app->createUser(['name' => 'Ada']);
    $userId = $u['user_id'];

    // Fund wallet ₦10,000 (1,000,000 kobo)
    $this->app->fundWallet(['user_id' => $userId, 'amount_kobo' => 1_000_000]);

    $ledger = new Ledger($this->pdo);
    $walletAcct = "user_wallet:$userId";

    $walletBefore = $ledger->balance($walletAcct);
    self::assertSame(1_000_000, $walletBefore);

    // Create transfer ₦5,000; fee ₦10 => total hold 501,000
    $tx = $this->app->createTransfer([
      'user_id' => $userId,
      'amount_kobo' => 500_000,
      'currency' => 'NGN',
      'bank_code' => '058',
      'bank_account' => '0123456789',
      'narration' => 'Test',
    ], 'idem-1');

    $transferId = $tx['transfer_id'];
    $holdAcct = "transfer_hold:$transferId";

    // Wallet reduced, hold increased
    self::assertSame(499_000, $ledger->balance($walletAcct));      // 1,000,000 - 501,000
    self::assertSame(501_000, $ledger->balance($holdAcct));

    // Simulate failed but debited -> should NOT refund wallet (Variant 1)
    $this->app->simulateTransferWebhook([
      'provider_event_id' => 'evt1',
      'type' => 'transfer_failed_debited',
      'payload' => ['transfer_id' => $transferId, 'failure_code' => '91', 'failure_reason' => 'timeout'],
    ]);
    $this->app->processTransferEvents();

    self::assertSame(499_000, $ledger->balance($walletAcct));
    self::assertSame(501_000, $ledger->balance($holdAcct));

    // Now reversal confirmed -> refund wallet
    $this->app->simulateTransferWebhook([
      'provider_event_id' => 'evt2',
      'type' => 'transfer_reversed',
      'payload' => ['transfer_id' => $transferId],
    ]);
    $this->app->processTransferEvents();

    self::assertSame(1_000_000, $ledger->balance($walletAcct));
    self::assertSame(0, $ledger->balance($holdAcct));
  }
}
