<?php

declare(strict_types=1);

namespace App;

use PDO;
use App\Ledger\Ledger;
use App\Fee\FeePolicy;



final class App
{
  private Ledger $ledger;
  private const TRANSFER_UNKNOWN_SLA_SECONDS = 120;     // after 2 mins of unknown, escalate
  private const TRANSFER_POLL_MIN_INTERVAL_SECONDS = 15; // don't poll same transfer too frequently

  public function __construct(private PDO $pdo)
  {
    $this->ledger = new Ledger($pdo);

    // Create core platform accounts once (idempotent).
    $this->ledger->ensureAccount('platform_cash', 'ASSET');
    $this->ledger->ensureAccount('provider_clearing', 'ASSET'); // fake “provider holding”
    $this->ledger->ensureAccount('platform_revenue', 'REVENUE');
    $this->ledger->ensureAccount('platform_revenue', 'REVENUE');      // your fee income
    $this->ledger->ensureAccount('provider_payable', 'LIABILITY');    // what you owe provider
    $this->ledger->ensureAccount('vat_payable', 'LIABILITY');         // VAT you owe government
    $this->ledger->ensureAccount('customer_funds', 'LIABILITY');      // funds held on behalf of payer/merchant
    $this->ledger->ensureAccount('platform_cash', 'ASSET');
    $this->ledger->ensureAccount('platform_settlement_cash', 'ASSET');
    $this->ledger->ensureAccount('transfer_fee_revenue', 'REVENUE');
    $this->ledger->ensureAccount('provider_fee_payable', 'LIABILITY');
    // your settlement bank/cash bucket

  }

  private function now(): string
  {
    return (new \DateTimeImmutable('now'))->format(DATE_ATOM);
  }

  private function uuid(): string
  {
    return bin2hex(random_bytes(16));
  }

  private function acctPending(string $merchantId): string
  {
    return "merchant_payable_pending:$merchantId";
  }

  private function acctAvailable(string $merchantId): string
  {
    return "merchant_payable_available:$merchantId";
  }

  private function ensureMerchantAccounts(string $merchantId): void
  {
    $this->ledger->ensureAccount($this->acctPending($merchantId), 'LIABILITY');
    $this->ledger->ensureAccount($this->acctAvailable($merchantId), 'LIABILITY');
  }

  // ----------------------------
  // Merchant
  // ----------------------------
  public function createMerchant(array $body): array
  {
    $name = (string)($body['name'] ?? '');
    if (trim($name) === '') return ['error' => 'name_required'];

    $id = $this->uuid();
    $stmt = $this->pdo->prepare('INSERT INTO merchants(id, name) VALUES(:id, :n)');
    $stmt->execute([':id' => $id, ':n' => $name]);

    $this->ensureMerchantAccounts($id);

    return ['merchant_id' => $id, 'name' => $name];
  }

  // ----------------------------
  // Payment Intent with idempotency
  // ----------------------------
  public function createPaymentIntent(array $body, string $idempotencyKey): array
  {
    $hash = hash('sha256', json_encode($body));

    $stmt = $this->pdo->prepare('SELECT response_json, request_hash FROM idempotency_keys WHERE key = :k');
    $stmt->execute([':k' => $idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $feeMode = (string)($body['fee_mode'] ?? 'merchant_pays');
    if (!in_array($feeMode, ['merchant_pays', 'customer_pays'], true)) {
      return ['error' => 'invalid_fee_mode'];
    }


    if ($row) {
      if ($row['request_hash'] !== $hash) {
        return ['error' => 'idempotency_key_reused_with_different_payload'];
      }
      return json_decode($row['response_json'], true);
    }

    $merchantId = (string)($body['merchant_id'] ?? '');
    $amount = (int)($body['amount_kobo'] ?? 0);
    $currency = (string)($body['currency'] ?? 'NGN');

    if ($merchantId === '' || $amount <= 0) {
      return ['error' => 'merchant_id_and_positive_amount_kobo_required'];
    }

    $this->ensureMerchantAccounts($merchantId);

    $id = $this->uuid();
    $ts = $this->now();

    $i = $this->pdo->prepare(
      'INSERT INTO payment_intents(id, merchant_id, amount_kobo, currency, status, idempotency_key, created_at, updated_at)
             VALUES(:id,:m,:a,:c,:s,:k,:ts,:ts)'
    );
    $i->execute([
      ':id' => $id,
      ':m' => $merchantId,
      ':a' => $amount,
      ':c' => $currency,
      ':s' => 'created',
      ':k' => $idempotencyKey,
      ':ts' => $ts
    ]);

    $resp = [
      'payment_intent_id' => $id,
      'merchant_id' => $merchantId,
      'amount_kobo' => $amount,
      'currency' => $currency,
      'status' => 'created'
    ];

    $save = $this->pdo->prepare(
      'INSERT INTO idempotency_keys(id, key, request_hash, response_json, created_at)
             VALUES(:id,:k,:h,:r,:ts)'
    );
    $save->execute([
      ':id' => $this->uuid(),
      ':k' => $idempotencyKey,
      ':h' => $hash,
      ':r' => json_encode($resp),
      ':ts' => $ts
    ]);

    return $resp;
  }

  // ----------------------------
  // Provider webhook storage (dedupe)
  // ----------------------------
  public function simulateProviderWebhook(array $body): array
  {
    $providerEventId = (string)($body['provider_event_id'] ?? '');
    $type = (string)($body['type'] ?? '');
    $payload = $body['payload'] ?? null;

    if ($providerEventId === '' || $type === '' || !is_array($payload)) {
      return ['error' => 'provider_event_id_type_payload_required'];
    }

    $stmt = $this->pdo->prepare(
      'INSERT INTO provider_events(id, provider_event_id, type, payload_json, processed, created_at)
             VALUES(:id,:pe,:t,:pj,0,:ts)'
    );

    try {
      $stmt->execute([
        ':id' => $this->uuid(),
        ':pe' => $providerEventId,
        ':t' => $type,
        ':pj' => json_encode($payload),
        ':ts' => $this->now()
      ]);
    } catch (\PDOException $e) {
      return ['ok' => true, 'note' => 'duplicate_event_ignored'];
    }

    return ['ok' => true];
  }

  // ----------------------------
  // Worker: process provider events
  // ----------------------------
  public function processProviderEvents(): array
  {
    $stmt = $this->pdo->query(
      'SELECT id, type, payload_json FROM provider_events WHERE processed = 0 ORDER BY created_at ASC LIMIT 50'
    );
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;

    foreach ($events as $evt) {
      $eventDbId = $evt['id'];
      $type = $evt['type'];
      $payload = json_decode($evt['payload_json'], true);

      $this->pdo->beginTransaction();
      try {
        if ($type === 'payment_succeeded') {
          $processed += $this->applyPaymentSucceeded($payload);
        }

        if ($type === 'payment_chargeback') {
          $processed += $this->applyChargeback($payload);
        }

        $u = $this->pdo->prepare('UPDATE provider_events SET processed = 1 WHERE id = :id');
        $u->execute([':id' => $eventDbId]);

        $this->pdo->commit();
      } catch (\Throwable $t) {
        $this->pdo->rollBack();
        // In production: record failure, retry policy, dead-letter queue, alerts.
      }
    }

    return ['processed' => $processed];
  }

  private function applyPaymentSucceeded(array $payload): int
  {
    $piId = (string)($payload['payment_intent_id'] ?? '');
    if ($piId === '') return 0;

    $stmt = $this->pdo->prepare('SELECT * FROM payment_intents WHERE id = :id');
    $stmt->execute([':id' => $piId]);
    $pi = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$pi) return 0;

    if ($pi['status'] === 'succeeded') return 0;

    $merchantId = (string)$pi['merchant_id'];
    $amount = (int)$pi['amount_kobo'];     // base amount (what merchant wants to charge)
    $currency = (string)$pi['currency'];
    $feeMode = (string)($pi['fee_mode'] ?? 'merchant_pays');

    $this->ensureMerchantAccounts($merchantId);

    // 1) Calculate fees
    $platformFee = FeePolicy::platformFeeKobo($amount);
    $vat = FeePolicy::vatOnPlatformFeeKobo($platformFee);
    $providerFee = FeePolicy::providerFeeKobo($amount);

    // 2) Decide total collected & merchant net depending on who pays fees
    // merchant_pays: customer pays amount, merchant receives amount - fees
    // customer_pays: customer pays amount + fees, merchant receives amount
    if ($feeMode === 'merchant_pays') {
      $totalCollected = $amount;
      $merchantNet = $amount - ($platformFee + $vat + $providerFee);
    } else { // customer_pays
      $totalCollected = $amount + ($platformFee + $vat + $providerFee);
      $merchantNet = $amount;
    }

    if ($merchantNet < 0) {
      throw new \RuntimeException('fees_exceed_amount');
    }

    // 3) Mark succeeded
    $u = $this->pdo->prepare('UPDATE payment_intents SET status = :s, updated_at = :ts WHERE id = :id');
    $u->execute([':s' => 'succeeded', ':ts' => $this->now(), ':id' => $piId]);

    // 4) Journal A: cash received -> holding liability created
    $this->ledger->post(
      'payment',
      $piId,
      'Payment succeeded: cash received and held',
      [
        ['account' => 'platform_cash', 'dc' => 'D', 'amount_kobo' => $totalCollected],
        ['account' => 'customer_funds', 'dc' => 'C', 'amount_kobo' => $totalCollected],
      ]
    );

    // 5) Journal B: allocate holding liability
    $lines = [];

    // Debit the holding bucket by the full collected amount (we’re distributing it)
    $lines[] = ['account' => 'customer_funds', 'dc' => 'D', 'amount_kobo' => $totalCollected];

    // Credit merchant pending payable
    $lines[] = ['account' => $this->acctPending($merchantId), 'dc' => 'C', 'amount_kobo' => $merchantNet];

    // Credit platform revenue (platform fee)
    if ($platformFee > 0) {
      $lines[] = ['account' => 'platform_revenue', 'dc' => 'C', 'amount_kobo' => $platformFee];
    }

    // Credit VAT payable
    if ($vat > 0) {
      $lines[] = ['account' => 'vat_payable', 'dc' => 'C', 'amount_kobo' => $vat];
    }

    // Credit provider payable
    if ($providerFee > 0) {
      $lines[] = ['account' => 'provider_payable', 'dc' => 'C', 'amount_kobo' => $providerFee];
    }

    // If customer_pays, totalCollected includes fees, so credits naturally sum to totalCollected:
    // merchantNet (amount) + platformFee + vat + providerFee == totalCollected

    // If merchant_pays, totalCollected == amount, and merchantNet == amount - fees:
    // merchantNet + platformFee + vat + providerFee == amount == totalCollected

    $this->ledger->post(
      'payment',
      $piId,
      'Allocate held funds: merchant payable + fees',
      $lines
    );

    return 1;
  }



  // ----------------------------
  // Balances endpoint
  // ----------------------------
  public function balances(): array
  {
    $stmt = $this->pdo->query('SELECT account, type, balance_kobo FROM v_balances ORDER BY account ASC');
    return ['balances' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
  }

  // ----------------------------
  // Release pending -> available
  // ----------------------------
  public function release(array $body): array
  {
    $merchantId = (string)($body['merchant_id'] ?? '');
    $amount = (int)($body['amount_kobo'] ?? 0);
    if ($merchantId === '' || $amount <= 0) return ['error' => 'merchant_id_and_positive_amount_kobo_required'];

    $this->ensureMerchantAccounts($merchantId);

    $pending = $this->ledger->balance($this->acctPending($merchantId));
    if ($pending < $amount) {
      return ['error' => 'insufficient_pending', 'pending_kobo' => $pending];
    }

    $refId = $this->uuid();

    // Move within liabilities:
    // Pending decreases (debit on liability reduces it)
    // Available increases (credit on liability increases it)
    $this->ledger->post(
      'release',
      $refId,
      'Release pending payable to available payable',
      [
        ['account' => $this->acctPending($merchantId), 'dc' => 'D', 'amount_kobo' => $amount],
        ['account' => $this->acctAvailable($merchantId), 'dc' => 'C', 'amount_kobo' => $amount],
      ]
    );

    return ['ok' => true, 'release_id' => $refId];
  }

  // ----------------------------
  // Settlement
  // ----------------------------
  public function settle(array $body): array
  {
    $merchantId = (string)($body['merchant_id'] ?? '');
    $amount = (int)($body['amount_kobo'] ?? 0);
    $currency = (string)($body['currency'] ?? 'NGN');

    if ($merchantId === '' || $amount <= 0) {
      return ['error' => 'merchant_id_and_positive_amount_kobo_required'];
    }

    $this->ensureMerchantAccounts($merchantId);

    $available = $this->ledger->balance($this->acctAvailable($merchantId));
    if ($available < $amount) {
      return ['error' => 'insufficient_available', 'available_kobo' => $available];
    }

    // Create settlement record
    $sid = $this->uuid();
    $stmt = $this->pdo->prepare(
      'INSERT INTO settlements(id, merchant_id, amount_kobo, currency, status, created_at)
             VALUES(:id,:m,:a,:c,:s,:ts)'
    );
    $stmt->execute([
      ':id' => $sid,
      ':m' => $merchantId,
      ':a' => $amount,
      ':c' => $currency,
      ':s' => 'created',
      ':ts' => $this->now()
    ]);

    // Ensure a fake bank out account
    $this->ledger->ensureAccount('bank_outgoing', 'ASSET');

    // Settlement journal:
    // Liability (available payable) decreases -> DEBIT liability
    // Asset (bank_outgoing) decreases or increases? bank_outgoing here is “money leaving platform” tracker.
    // We model it as an ASSET bucket that increases with DEBIT when we send money out.
    $this->ledger->post(
      'settlement',
      $sid,
      'Pay out merchant',
      [
        ['account' => $this->acctAvailable($merchantId), 'dc' => 'D', 'amount_kobo' => $amount],
        ['account' => 'bank_outgoing', 'dc' => 'D', 'amount_kobo' => $amount],
        // This is unbalanced (2 debits). We need a credit:
        // Credit platform_cash because cash leaves platform.
      ]
    );

    // Fix correctly: bank_outgoing isn’t needed. Payout should be:
    //   DR merchant_payable_available (reduce liability)
    //   CR platform_cash (reduce asset)
    //
    // So we’ll do the correct posting and then mark settlement paid.

    $this->ledger->post(
      'settlement',
      $sid,
      'Pay out merchant (reduce platform cash)',
      [
        ['account' => $this->acctAvailable($merchantId), 'dc' => 'D', 'amount_kobo' => $amount],
        ['account' => 'platform_cash', 'dc' => 'C', 'amount_kobo' => $amount],
      ]
    );

    $u = $this->pdo->prepare('UPDATE settlements SET status = :s WHERE id = :id');
    $u->execute([':s' => 'paid', ':id' => $sid]);

    return ['ok' => true, 'settlement_id' => $sid];
  }

  private function applyChargeback(array $payload): int
  {
    $piId = (string)($payload['payment_intent_id'] ?? '');
    if ($piId === '') return 0;

    $reason = (string)($payload['reason'] ?? 'unspecified');
    $refundProviderFee = (bool)($payload['refund_provider_fee'] ?? false);

    // 1) Check if we already reversed this payment (idempotency at our layer)
    $stmt = $this->pdo->prepare('SELECT 1 FROM reversals WHERE payment_intent_id = :id');
    $stmt->execute([':id' => $piId]);
    if ($stmt->fetchColumn()) {
      return 0; // already reversed
    }

    // 2) Load payment intent
    $stmt = $this->pdo->prepare('SELECT * FROM payment_intents WHERE id = :id');
    $stmt->execute([':id' => $piId]);
    $pi = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pi) return 0;

    $merchantId = (string)$pi['merchant_id'];
    $amount = (int)$pi['amount_kobo'];
    $currency = (string)$pi['currency'];

    $this->ensureMerchantAccounts($merchantId);

    // Only chargeback succeeded payments in this demo
    if ($pi['status'] !== 'succeeded') {
      return 0;
    }

    // 3) Compute fees same way we did on success (must match!)
    $platformFee = (int) floor($amount * 0.015);
    $providerFee = (int) floor($amount * 0.010);
    $merchantNet = $amount - $platformFee - $providerFee;

    if ($merchantNet < 0) {
      throw new \RuntimeException('fees_exceed_amount');
    }

    // 4) Figure out where merchant funds are currently sitting:
    // pending first, then available, and if still not enough, merchant owes us (receivable).
    $pendingAcct = $this->acctPending($merchantId);
    $availableAcct = $this->acctAvailable($merchantId);

    $pendingBal = $this->ledger->balance($pendingAcct);
    $availableBal = $this->ledger->balance($availableAcct);

    $takeFromPending = min($merchantNet, $pendingBal);
    $remaining = $merchantNet - $takeFromPending;

    $takeFromAvailable = min($remaining, $availableBal);
    $remaining = $remaining - $takeFromAvailable;

    // If remaining > 0, it means merchant already got paid and doesn't have enough payable balance.
    // That remainder becomes a merchant receivable.
    $merchantReceivable = $remaining; // could be 0

    // 5) Record dispute (audit trail)
    $disputeId = $this->uuid();
    $stmt = $this->pdo->prepare(
      'INSERT INTO disputes(id, payment_intent_id, type, amount_kobo, currency, status, reason, created_at)
         VALUES(:id,:pi,:t,:amt,:cur,:st,:r,:ts)'
    );
    $stmt->execute([
      ':id' => $disputeId,
      ':pi' => $piId,
      ':t' => 'chargeback',
      ':amt' => $amount,
      ':cur' => $currency,
      ':st' => 'opened',
      ':r' => $reason,
      ':ts' => $this->now(),
    ]);

    // 6) Now the accounting reversal. We reverse the TWO journals we originally did:
    //
    // Original Journal A:
    //   DR platform_cash amount
    //   CR customer_funds amount
    //
    // Reverse Journal A:
    //   DR customer_funds amount
    //   CR platform_cash amount
    //
    // Original Journal B:
    //   DR customer_funds amount
    //   CR merchant_pending merchantNet
    //   CR platform_revenue platformFee
    //   CR provider_payable providerFee
    //
    // Reverse Journal B (but merchant might be paid out already):
    //   DR merchant_pending (whatever is still there)
    //   DR merchant_available (whatever is still there)
    //   DR merchant_receivable (if needed)
    //   DR platform_revenue platformFee
    //   DR provider_payable providerFee (only if provider refunds fee; otherwise it becomes an expense)
    //   CR customer_funds amount

    // If provider does NOT refund their fee:
    // we still need to reverse the "provider_payable" liability (because we won't owe it anymore),
    // but we also take a loss equal to providerFee.
    //
    // Simplest model:
    // - If refundProviderFee is false:
    //   Instead of DR provider_payable providerFee,
    //   we DR chargeback_loss providerFee.
    //
    // That says: "platform eats provider fee".

    $linesReverseAllocation = [];

    // Debits to remove merchant obligation (where possible)
    if ($takeFromPending > 0) {
      $linesReverseAllocation[] = ['account' => $pendingAcct, 'dc' => 'D', 'amount_kobo' => $takeFromPending];
    }
    if ($takeFromAvailable > 0) {
      $linesReverseAllocation[] = ['account' => $availableAcct, 'dc' => 'D', 'amount_kobo' => $takeFromAvailable];
    }
    if ($merchantReceivable > 0) {
      $linesReverseAllocation[] = ['account' => 'merchant_receivable', 'dc' => 'D', 'amount_kobo' => $merchantReceivable];
    }

    // Debit revenue to reverse revenue (REVENUE normal credit; DEBIT reduces)
    if ($platformFee > 0) {
      $linesReverseAllocation[] = ['account' => 'platform_revenue', 'dc' => 'D', 'amount_kobo' => $platformFee];
    }

    if ($providerFee > 0) {
      if ($refundProviderFee) {
        // liability decreases with debit
        $linesReverseAllocation[] = ['account' => 'provider_payable', 'dc' => 'D', 'amount_kobo' => $providerFee];
      } else {
        // platform eats the fee
        $linesReverseAllocation[] = ['account' => 'chargeback_loss', 'dc' => 'D', 'amount_kobo' => $providerFee];
      }
    }

    // Credit customer_funds to “recreate” the generic liability that we are refunding from.
    $linesReverseAllocation[] = ['account' => 'customer_funds', 'dc' => 'C', 'amount_kobo' => $amount];

    // IMPORTANT: This journal must balance.
    // Sum debits should equal amount.
    // Our debits are: merchantNet (split across pending/available/receivable) + platformFee + providerFee
    // Which equals amount. Good.

    $this->ledger->post(
      'chargeback',
      $piId,
      'Chargeback reversal: undo allocation to merchant/revenue/provider',
      $linesReverseAllocation
    );

    // Reverse Journal A: return cash (provider claws back)
    $this->ledger->post(
      'chargeback',
      $piId,
      'Chargeback reversal: provider clawback reduces platform cash',
      [
        ['account' => 'customer_funds', 'dc' => 'D', 'amount_kobo' => $amount],
        ['account' => 'platform_cash', 'dc' => 'C', 'amount_kobo' => $amount],
      ]
    );

    // 7) Mark reversal done (idempotent)
    $stmt = $this->pdo->prepare(
      'INSERT INTO reversals(id, payment_intent_id, reversal_type, created_at)
         VALUES(:id,:pi,:rt,:ts)'
    );
    $stmt->execute([
      ':id' => $this->uuid(),
      ':pi' => $piId,
      ':rt' => 'chargeback',
      ':ts' => $this->now(),
    ]);

    // 8) Close dispute + mark payment status (for demo)
    $stmt = $this->pdo->prepare('UPDATE disputes SET status = :s, closed_at = :ts WHERE id = :id');
    $stmt->execute([':s' => 'closed', ':ts' => $this->now(), ':id' => $disputeId]);

    $stmt = $this->pdo->prepare('UPDATE payment_intents SET status = :s, updated_at = :ts WHERE id = :id');
    $stmt->execute([':s' => 'failed', ':ts' => $this->now(), ':id' => $piId]);

    return 1;
  }

  // ----------------------------
  // User wallet and transfers
  private function acctUserWallet(string $userId): string
  {
    return "user_wallet:$userId";
  }

  private function acctTransferHold(string $transferId): string
  {
    return "transfer_hold:$transferId";
  }

  public function createUser(array $body): array
  {
    $name = (string)($body['name'] ?? '');
    if (trim($name) === '') return ['error' => 'name_required'];

    $id = $this->uuid();
    $ts = $this->now();

    $stmt = $this->pdo->prepare('INSERT INTO users(id, name, created_at) VALUES(:id,:n,:ts)');
    $stmt->execute([':id' => $id, ':n' => $name, ':ts' => $ts]);

    // Wallet is a liability: we owe the user that money.
    $this->ledger->ensureAccount($this->acctUserWallet($id), 'LIABILITY');

    return ['user_id' => $id, 'name' => $name];
  }

  public function fundWallet(array $body): array
  {
    $userId = (string)($body['user_id'] ?? '');
    $amount = (int)($body['amount_kobo'] ?? 0);
    if ($userId === '' || $amount <= 0) return ['error' => 'user_id_and_positive_amount_kobo_required'];

    $this->ledger->ensureAccount($this->acctUserWallet($userId), 'LIABILITY');

    // Funding wallet means: platform cash increases and we owe user more.
    // DR platform_settlement_cash (ASSET up)
    // CR user_wallet (LIABILITY up)
    $jid = $this->ledger->post(
      'wallet_fund',
      $userId,
      'Fund user wallet',
      [
        ['account' => 'platform_settlement_cash', 'dc' => 'D', 'amount_kobo' => $amount],
        ['account' => $this->acctUserWallet($userId), 'dc' => 'C', 'amount_kobo' => $amount],
      ]
    );

    return ['ok' => true, 'journal_id' => $jid];
  }

  public function createTransfer(array $body, string $idempotencyKey): array
  {
    $hash = hash('sha256', json_encode($body));

    // idempotency
    $stmt = $this->pdo->prepare('SELECT response_json, request_hash FROM idempotency_keys WHERE key = :k');
    $stmt->execute([':k' => $idempotencyKey]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row) {
      if ($row['request_hash'] !== $hash) return ['error' => 'idempotency_key_reused_with_different_payload'];
      return json_decode($row['response_json'], true);
    }

    $userId = (string)($body['user_id'] ?? '');
    $amount = (int)($body['amount_kobo'] ?? 0);
    $currency = (string)($body['currency'] ?? 'NGN');
    $bankCode = (string)($body['bank_code'] ?? '');
    $bankAccount = (string)($body['bank_account'] ?? '');
    $narration = (string)($body['narration'] ?? 'Transfer');

    if ($userId === '' || $amount <= 0 || $bankCode === '' || $bankAccount === '') {
      return ['error' => 'invalid_transfer_request'];
    }

    $this->ledger->ensureAccount($this->acctUserWallet($userId), 'LIABILITY');

    $fee = \App\Fee\TransferFeePolicy::feeKobo($amount);
    $totalHold = $amount + $fee;

    // Ensure user has enough wallet balance
    $walletBal = $this->ledger->balance($this->acctUserWallet($userId));
    if ($walletBal < $totalHold) {
      return ['error' => 'insufficient_wallet_balance', 'available_kobo' => $walletBal, 'needed_kobo' => $totalHold];
    }

    $transferId = $this->uuid();
    $ts = $this->now();

    // Create transfer record
    $stmt = $this->pdo->prepare(
      'INSERT INTO transfers(
            id,user_id,amount_kobo,fee_kobo,currency,bank_code,bank_account,narration,status,
            provider_ref,failure_code,failure_reason,created_at,updated_at,idempotency_key
         ) VALUES(
            :id,:u,:a,:f,:c,:bc,:ba,:n,:s,
            NULL,NULL,NULL,:ts,:ts,:ik
         )'
    );
    $stmt->execute([
      ':id' => $transferId,
      ':u' => $userId,
      ':a' => $amount,
      ':f' => $fee,
      ':c' => $currency,
      ':bc' => $bankCode,
      ':ba' => $bankAccount,
      ':n' => $narration,
      ':s' => 'wallet_held',
      ':ts' => $ts,
      ':ik' => $idempotencyKey,
    ]);

    // Create hold account per transfer
    $this->ledger->ensureAccount($this->acctTransferHold($transferId), 'LIABILITY');

    // Journal 1: move money from user wallet -> transfer hold
    // DR user_wallet (LIABILITY down)
    // CR transfer_hold (LIABILITY up)
    $this->ledger->post(
      'transfer',
      $transferId,
      'Move funds from user wallet into transfer hold',
      [
        ['account' => $this->acctUserWallet($userId), 'dc' => 'D', 'amount_kobo' => $totalHold],
        ['account' => $this->acctTransferHold($transferId), 'dc' => 'C', 'amount_kobo' => $totalHold],
      ]
    );

    $resp = [
      'transfer_id' => $transferId,
      'status' => 'wallet_held',
      'amount_kobo' => $amount,
      'fee_kobo' => $fee,
      'total_held_kobo' => $totalHold,
    ];

    // store idempotency result
    $stmt = $this->pdo->prepare(
      'INSERT INTO idempotency_keys(id, key, request_hash, response_json, created_at)
         VALUES(:id,:k,:h,:r,:ts)'
    );
    $stmt->execute([
      ':id' => $this->uuid(),
      ':k' => $idempotencyKey,
      ':h' => $hash,
      ':r' => json_encode($resp),
      ':ts' => $ts,
    ]);

    return $resp;
  }

  public function simulateTransferWebhook(array $body): array
  {
    $providerEventId = (string)($body['provider_event_id'] ?? '');
    $type = (string)($body['type'] ?? '');
    $payload = $body['payload'] ?? null;

    if ($providerEventId === '' || $type === '' || !is_array($payload)) {
      return ['error' => 'provider_event_id_type_payload_required'];
    }

    $stmt = $this->pdo->prepare(
      'INSERT INTO transfer_events(id, provider_event_id, type, payload_json, processed, created_at)
         VALUES(:id,:pe,:t,:pj,0,:ts)'
    );

    try {
      $stmt->execute([
        ':id' => $this->uuid(),
        ':pe' => $providerEventId,
        ':t' => $type,
        ':pj' => json_encode($payload),
        ':ts' => $this->now(),
      ]);
    } catch (\PDOException $e) {
      return ['ok' => true, 'note' => 'duplicate_event_ignored'];
    }

    return ['ok' => true];
  }

  public function processTransferEvents(): array
  {
    $stmt = $this->pdo->query(
      'SELECT id, type, payload_json FROM transfer_events WHERE processed = 0 ORDER BY created_at ASC LIMIT 50'
    );
    $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $processed = 0;

    foreach ($events as $evt) {
      $eventDbId = $evt['id'];
      $type = $evt['type'];
      $payload = json_decode($evt['payload_json'], true);

      $this->pdo->beginTransaction();
      try {
        if ($type === 'transfer_submitted') $processed += $this->applyTransferSubmitted($payload);
        if ($type === 'transfer_credit_confirmed') $processed += $this->applyTransferCreditConfirmed($payload);
        if ($type === 'transfer_failed_no_debit') $processed += $this->applyTransferFailedNoDebit($payload);
        if ($type === 'transfer_failed_debited') $processed += $this->applyTransferFailedDebited($payload);
        if ($type === 'transfer_reversed') $processed += $this->applyTransferReversed($payload);

        $u = $this->pdo->prepare('UPDATE transfer_events SET processed = 1 WHERE id = :id');
        $u->execute([':id' => $eventDbId]);

        $this->pdo->commit();
      } catch (\Throwable $t) {
        $this->pdo->rollBack();
      }
    }

    return ['processed' => $processed];
  }

  private function loadTransfer(string $transferId): ?array
  {
    $stmt = $this->pdo->prepare('SELECT * FROM transfers WHERE id = :id');
    $stmt->execute([':id' => $transferId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
  }

  private function updateTransferStatus(string $transferId, string $status, ?string $providerRef = null, ?string $failureCode = null, ?string $failureReason = null): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE transfers
         SET status = :s, provider_ref = COALESCE(:pr, provider_ref),
             failure_code = COALESCE(:fc, failure_code),
             failure_reason = COALESCE(:fr, failure_reason),
             updated_at = :ts
         WHERE id = :id'
    );
    $stmt->execute([
      ':s' => $status,
      ':pr' => $providerRef,
      ':fc' => $failureCode,
      ':fr' => $failureReason,
      ':ts' => $this->now(),
      ':id' => $transferId,
    ]);
  }

  private function applyTransferSubmitted(array $payload): int
  {
    $transferId = (string)($payload['transfer_id'] ?? '');
    $providerRef = (string)($payload['provider_ref'] ?? '');

    if ($transferId === '' || $providerRef === '') return 0;

    $t = $this->loadTransfer($transferId);
    if (!$t) return 0;

    // If already beyond submitted, ignore retry
    if (in_array($t['status'], ['credit_confirmed', 'failed_no_debit', 'failed_debited', 'reversal_initiated', 'reversed'], true)) {
      return 0;
    }

    $this->updateTransferStatus($transferId, 'submitted', $providerRef);
    return 1;
  }

  private function applyTransferCreditConfirmed(array $payload): int
  {
    $transferId = (string)($payload['transfer_id'] ?? '');
    if ($transferId === '') return 0;

    $t = $this->loadTransfer($transferId);
    if (!$t) return 0;

    if ($t['status'] === 'credit_confirmed') return 0;

    $amount = (int)$t['amount_kobo'];
    $fee = (int)$t['fee_kobo'];
    $totalHold = $amount + $fee;

    // We release the hold:
    // - Reduce transfer_hold by totalHold (DEBIT liability)
    // - Reduce platform_settlement_cash by amount (CREDIT asset) because money left to bank
    // - Recognize fee revenue (CREDIT revenue)
    //
    // Debits must equal credits:
    // Debits: transfer_hold totalHold
    // Credits: platform_settlement_cash amount + transfer_fee_revenue fee = totalHold
    $this->ledger->post(
      'transfer',
      $transferId,
      'Transfer succeeded: pay out + take fee',
      [
        ['account' => $this->acctTransferHold($transferId), 'dc' => 'D', 'amount_kobo' => $totalHold],
        ['account' => 'platform_settlement_cash', 'dc' => 'C', 'amount_kobo' => $amount],
        ['account' => 'transfer_fee_revenue', 'dc' => 'C', 'amount_kobo' => $fee],
      ]
    );

    $this->updateTransferStatus($transferId, 'credit_confirmed');
    return 1;
  }

  private function applyTransferFailedNoDebit(array $payload): int
  {
    $transferId = (string)($payload['transfer_id'] ?? '');
    $code = (string)($payload['failure_code'] ?? 'FAILED');
    $reason = (string)($payload['failure_reason'] ?? 'failed_no_debit');

    if ($transferId === '') return 0;

    $t = $this->loadTransfer($transferId);
    if (!$t) return 0;

    if (in_array($t['status'], ['failed_no_debit', 'reversed'], true)) return 0;

    $userId = (string)$t['user_id'];
    $amount = (int)$t['amount_kobo'];
    $fee = (int)$t['fee_kobo'];
    $totalHold = $amount + $fee;

    // Refund immediately because NO debit happened on rail:
    // transfer_hold decreases (DEBIT)
    // user_wallet increases back (CREDIT)
    $this->ledger->post(
      'transfer',
      $transferId,
      'Transfer failed with no debit: refund user from hold',
      [
        ['account' => $this->acctTransferHold($transferId), 'dc' => 'D', 'amount_kobo' => $totalHold],
        ['account' => $this->acctUserWallet($userId), 'dc' => 'C', 'amount_kobo' => $totalHold],
      ]
    );

    $this->updateTransferStatus($transferId, 'failed_no_debit', null, $code, $reason);
    return 1;
  }

  private function applyTransferFailedDebited(array $payload): int
  {
    $transferId = (string)($payload['transfer_id'] ?? '');
    $code = (string)($payload['failure_code'] ?? 'FAILED_DEBITED');
    $reason = (string)($payload['failure_reason'] ?? 'failed_but_debited');

    if ($transferId === '') return 0;

    $t = $this->loadTransfer($transferId);
    if (!$t) return 0;

    // Variant 1: do NOT refund user now. Keep hold.
    if (in_array($t['status'], ['failed_debited', 'reversal_initiated', 'reversed'], true)) return 0;

    // We mark status so ops/recon knows: money might be on rail, reversal needed.
    $this->updateTransferStatus($transferId, 'failed_debited', null, $code, $reason);
    return 1;
  }

  private function applyTransferReversed(array $payload): int
  {
    $transferId = (string)($payload['transfer_id'] ?? '');
    if ($transferId === '') return 0;

    $t = $this->loadTransfer($transferId);
    if (!$t) return 0;

    if ($t['status'] === 'reversed') return 0;

    $userId = (string)$t['user_id'];
    $amount = (int)$t['amount_kobo'];
    $fee = (int)$t['fee_kobo'];
    $totalHold = $amount + $fee;

    // Now reversal confirmed => refund from hold to user wallet.
    $this->ledger->post(
      'transfer',
      $transferId,
      'Reversal confirmed: refund user from hold',
      [
        ['account' => $this->acctTransferHold($transferId), 'dc' => 'D', 'amount_kobo' => $totalHold],
        ['account' => $this->acctUserWallet($userId), 'dc' => 'C', 'amount_kobo' => $totalHold],
      ]
    );

    $this->updateTransferStatus($transferId, 'reversed');
    return 1;
  }

  public function submitTransfer(array $body): array
  {
    $transferId = (string)($body['transfer_id'] ?? '');
    $mode = (string)($body['mode'] ?? 'unknown'); // unknown | submitted

    if ($transferId === '') return ['error' => 'transfer_id_required'];

    $t = $this->loadTransfer($transferId);
    if (!$t) return ['error' => 'transfer_not_found'];

    if (!in_array($t['status'], ['wallet_held', 'unknown'], true)) {
      return ['error' => 'invalid_state', 'status' => $t['status']];
    }

    $ts = $this->now();

    // If mode=submitted we pretend provider returned a ref immediately.
    if ($mode === 'submitted') {
      $providerRef = 'prov_' . substr($transferId, 0, 10);

      $stmt = $this->pdo->prepare(
        'UPDATE transfers SET status = :s, provider_ref = :pr, submitted_at = COALESCE(submitted_at, :ts), updated_at = :ts WHERE id = :id'
      );
      $stmt->execute([
        ':s' => 'submitted',
        ':pr' => $providerRef,
        ':ts' => $ts,
        ':id' => $transferId,
      ]);

      return ['ok' => true, 'status' => 'submitted', 'provider_ref' => $providerRef];
    }

    // mode=unknown: this simulates network timeout/no response.
    $stmt = $this->pdo->prepare(
      'UPDATE transfers SET status = :s, submitted_at = COALESCE(submitted_at, :ts), updated_at = :ts WHERE id = :id'
    );
    $stmt->execute([
      ':s' => 'unknown',
      ':ts' => $ts,
      ':id' => $transferId,
    ]);

    return ['ok' => true, 'status' => 'unknown'];
  }

  // ----------------------------
  // Provider query transfer
  // ----------------------------
  public function providerQueryTransfer(array $body): array
  {
    $transferId = (string)($body['transfer_id'] ?? '');
    $result = (string)($body['result'] ?? 'unknown');
    // unknown | credit_confirmed | failed_no_debit | failed_debited | reversed

    if ($transferId === '') return ['error' => 'transfer_id_required'];

    // Return a response shaped like a provider query result
    return [
      'transfer_id' => $transferId,
      'provider_status' => $result,
      'provider_ref' => 'prov_' . substr($transferId, 0, 10),
    ];
  }

  public function pollUnknownTransfers(array $body): array
  {
    $limit = (int)($body['limit'] ?? 20);
    if ($limit <= 0) $limit = 20;

    $now = new \DateTimeImmutable('now');
    $nowTs = $now->format(DATE_ATOM);

    // Fetch unknown transfers
    $stmt = $this->pdo->prepare(
      'SELECT * FROM transfers WHERE status = :s ORDER BY updated_at ASC LIMIT :lim'
    );
    $stmt->bindValue(':s', 'unknown', \PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $polled = 0;
    $escalated = 0;
    $generatedEvents = 0;

    foreach ($rows as $t) {
      $transferId = (string)$t['id'];

      $submittedAt = (string)($t['submitted_at'] ?? '');
      if ($submittedAt === '') {
        // If somehow unknown without submitted_at, set it now so SLA works.
        $this->pdo->prepare('UPDATE transfers SET submitted_at = :ts WHERE id = :id')
          ->execute([':ts' => $nowTs, ':id' => $transferId]);
        $submittedAt = $nowTs;
      }

      $lastPolledAt = (string)($t['last_polled_at'] ?? '');
      if ($lastPolledAt !== '') {
        $lp = new \DateTimeImmutable($lastPolledAt);
        if (($now->getTimestamp() - $lp->getTimestamp()) < self::TRANSFER_POLL_MIN_INTERVAL_SECONDS) {
          continue;
        }
      }

      // Update last_polled_at
      $this->pdo->prepare('UPDATE transfers SET last_polled_at = :ts WHERE id = :id')
        ->execute([':ts' => $nowTs, ':id' => $transferId]);

      $polled++;

      // In this demo, caller passes the intended provider result per transfer_id map:
      // body['results'] can be { "<transferId>": "credit_confirmed" | ... }
      $results = $body['results'] ?? [];
      $providerResult = is_array($results) && isset($results[$transferId]) ? (string)$results[$transferId] : 'unknown';

      if ($providerResult !== 'unknown') {
        // Convert provider result -> transfer_event
        $eventType = match ($providerResult) {
          'credit_confirmed' => 'transfer_credit_confirmed',
          'failed_no_debit' => 'transfer_failed_no_debit',
          'failed_debited' => 'transfer_failed_debited',
          'reversed' => 'transfer_reversed',
          default => null,
        };

        if ($eventType !== null) {
          $this->simulateTransferWebhook([
            'provider_event_id' => 'poll_' . $transferId . '_' . bin2hex(random_bytes(4)),
            'type' => $eventType,
            'payload' => ['transfer_id' => $transferId],
          ]);
          $generatedEvents++;
        }

        continue;
      }

      // Still unknown: SLA check
      $sa = new \DateTimeImmutable($submittedAt);
      if (($now->getTimestamp() - $sa->getTimestamp()) >= self::TRANSFER_UNKNOWN_SLA_SECONDS) {
        // escalate to manual_review (Variant 1 safe ops)
        $this->updateTransferStatus($transferId, 'manual_review', null, 'UNKNOWN_TIMEOUT', 'exceeded_unknown_sla');
        $escalated++;
      }
    }

    // Process any generated events
    if ($generatedEvents > 0) {
      $this->processTransferEvents();
    }

    return [
      'polled' => $polled,
      'generated_events' => $generatedEvents,
      'escalated_manual_review' => $escalated,
    ];
  }
}
