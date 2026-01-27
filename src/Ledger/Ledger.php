<?php

declare(strict_types=1);

namespace App\Ledger;

use PDO;

final class Ledger
{
  public function __construct(private PDO $pdo) {}

  private function now(): string
  {
    return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
  }

  private function uuid(): string
  {
    return bin2hex(random_bytes(16));
  }

  public function ensureAccount(string $account, string $type): void
  {
    // If account exists, do nothing.
    // If missing, insert it.
    $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO accounts(account, type) VALUES(:a, :t)');
    $stmt->execute([':a' => $account, ':t' => $type]);
  }

  public function balance(string $account): int
  {
    $stmt = $this->pdo->prepare('SELECT balance_kobo FROM v_balances WHERE account = :a');
    $stmt->execute([':a' => $account]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['balance_kobo'] : 0;
  }

  /**
   * Create a journal and postings in one DB transaction.
   *
   * @param array<int, array{account:string, dc:'D'|'C', amount_kobo:int}> $lines
   */
  public function post(string $refType, string $refId, string $memo, array $lines): string
  {
    // Rule of double-entry:
    // total debits == total credits
    $debits = 0;
    $credits = 0;

    foreach ($lines as $l) {
      if ($l['amount_kobo'] <= 0) {
        throw new \RuntimeException('amount_kobo must be positive');
      }
      if ($l['dc'] === 'D') $debits += $l['amount_kobo'];
      if ($l['dc'] === 'C') $credits += $l['amount_kobo'];
    }

    if ($debits !== $credits) {
      throw new \RuntimeException('unbalanced_journal: debits != credits');
    }

    $this->pdo->beginTransaction();

    try {
      $journalId = $this->uuid();
      $ts = $this->now();

      $j = $this->pdo->prepare(
        'INSERT INTO journals(id, ref_type, ref_id, memo, created_at)
                 VALUES(:id, :rt, :rid, :m, :ts)'
      );
      $j->execute([
        ':id' => $journalId,
        ':rt' => $refType,
        ':rid' => $refId,
        ':m' => $memo,
        ':ts' => $ts,
      ]);

      $p = $this->pdo->prepare(
        'INSERT INTO postings(id, journal_id, account, dc, amount_kobo, created_at)
                 VALUES(:id, :jid, :acct, :dc, :amt, :ts)'
      );

      foreach ($lines as $l) {
        $p->execute([
          ':id' => $this->uuid(),
          ':jid' => $journalId,
          ':acct' => $l['account'],
          ':dc' => $l['dc'],
          ':amt' => $l['amount_kobo'],
          ':ts' => $ts,
        ]);
      }

      $this->pdo->commit();
      return $journalId;
    } catch (\Throwable $t) {
      $this->pdo->rollBack();
      throw $t;
    }
  }
}
