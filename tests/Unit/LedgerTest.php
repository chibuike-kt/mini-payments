<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Db\Db;
use App\Db\Migrate;
use App\Ledger\Ledger;

final class LedgerTest extends TestCase
{
  private \PDO $pdo;

  protected function setUp(): void
  {
    // Use in-memory SQLite for fast tests
    $this->pdo = Db::pdo(':memory:');

    // Use your schema v2 (must exist)
    Migrate::run($this->pdo, __DIR__ . '/../../database/schema_v2.sql');
  }

  public function testBalancedJournalPosts(): void
  {
    $ledger = new Ledger($this->pdo);

    $ledger->ensureAccount('cash', 'ASSET');
    $ledger->ensureAccount('customer_funds', 'LIABILITY');

    $jid = $ledger->post('test', 'ref1', 'balanced', [
      ['account' => 'cash', 'dc' => 'D', 'amount_kobo' => 100],
      ['account' => 'customer_funds', 'dc' => 'C', 'amount_kobo' => 100],
    ]);

    self::assertNotEmpty($jid);

    // Cash (ASSET) debit increases -> should be +100
    self::assertSame(100, $ledger->balance('cash'));

    // Liability credit increases -> should be +100
    self::assertSame(100, $ledger->balance('customer_funds'));
  }

  public function testUnbalancedJournalThrows(): void
  {
    $ledger = new Ledger($this->pdo);

    $ledger->ensureAccount('cash', 'ASSET');
    $ledger->ensureAccount('customer_funds', 'LIABILITY');

    $this->expectException(RuntimeException::class);

    $ledger->post('test', 'ref2', 'unbalanced', [
      ['account' => 'cash', 'dc' => 'D', 'amount_kobo' => 100],
      ['account' => 'customer_funds', 'dc' => 'C', 'amount_kobo' => 99],
    ]);
  }
}
