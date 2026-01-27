<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Db\Db;
use App\Db\Migrate;
use App\App;

final class TransferUnknownSlaTest extends TestCase
{
  private PDO $pdo;
  private App $app;

  protected function setUp(): void
  {
    $this->pdo = Db::pdo(':memory:');
    Migrate::run($this->pdo, __DIR__ . '/../../database/schema_v2.sql');
    Migrate::run($this->pdo, __DIR__ . '/../../database/schema_transfers.sql');

    // add the two columns in memory DB too
    $this->pdo->exec("ALTER TABLE transfers ADD COLUMN submitted_at TEXT;");
    $this->pdo->exec("ALTER TABLE transfers ADD COLUMN last_polled_at TEXT;");

    $this->app = new App($this->pdo);
  }

  public function testUnknownEscalatesToManualReviewAfterSla(): void
  {
    $u = $this->app->createUser(['name' => 'Ada']);
    $userId = $u['user_id'];

    $this->app->fundWallet(['user_id' => $userId, 'amount_kobo' => 1_000_000]);

    $tx = $this->app->createTransfer([
      'user_id' => $userId,
      'amount_kobo' => 500_000,
      'currency' => 'NGN',
      'bank_code' => '058',
      'bank_account' => '0123456789',
      'narration' => 'Test',
    ], 'idem-unknown');

    $transferId = $tx['transfer_id'];

    // submit as unknown
    $this->app->submitTransfer(['transfer_id' => $transferId, 'mode' => 'unknown']);

    // Force submitted_at to be old enough to exceed SLA
    $old = (new DateTimeImmutable('now'))->modify('-10 minutes')->format(DATE_ATOM);
    $this->pdo->prepare('UPDATE transfers SET submitted_at = :old WHERE id = :id')->execute([
      ':old' => $old,
      ':id' => $transferId,
    ]);

    $out = $this->app->pollUnknownTransfers(['results' => []]);
    self::assertGreaterThanOrEqual(1, $out['escalated_manual_review']);

    $stmt = $this->pdo->prepare('SELECT status FROM transfers WHERE id = :id');
    $stmt->execute([':id' => $transferId]);
    $status = $stmt->fetchColumn();

    self::assertSame('manual_review', $status);
  }
}
