<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Db\Db;
use App\Db\Migrate;
use App\Http\Router;

$databasePath = __DIR__ . '/../database/app.sqlite';
$schemaFile = __DIR__ . '/../database/schema_v2.sql';


// Create DB connection
$pdo = Db::pdo($databasePath);

// Run migrations (safe enough for demo: will error if tables already exist)
try {
  Migrate::run($pdo, $schemaFile);
} catch (Throwable $t) {
  // Ignore if already migrated
}

$app = new App($pdo);
$router = new Router();

// Health check
$router->add('GET', '/', fn() => ['ok' => true]);

// Create merchant
$router->add('POST', '/merchants', fn($body) => $app->createMerchant($body));

$router->add('POST', '/release', fn($body) => $app->release($body));

$router->add('GET', '/disputes', function () use ($pdo) {
  $stmt = $pdo->query('SELECT * FROM disputes ORDER BY created_at DESC LIMIT 50');
  return ['disputes' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
});



// Create payment intent (requires Idempotency-Key header)
$router->add('POST', '/payment_intents', function ($body) use ($app) {
  $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
  if (trim($idempotencyKey) === '') {
    return ['error' => 'Idempotency-Key header required'];
  }
  return $app->createPaymentIntent($body, $idempotencyKey);
});

// Simulate provider webhook event creation
$router->add('POST', '/provider/webhook', fn($body) => $app->simulateProviderWebhook($body));

// Process provider events (worker trigger)
$router->add('POST', '/provider/process', fn() => $app->processProviderEvents());

// View balances
$router->add('GET', '/balances', fn() => $app->balances());

// Settlement
$router->add('POST', '/settle', fn($body) => $app->settle($body));

$router->dispatch();
// When customer pays, platform “cash” increases,
// and platform also owes merchant → merchant_payable increases.

$router->add('POST', '/fees/quote', function ($body) {
  $amount = (int)($body['amount_kobo'] ?? 0);
  $mode = (string)($body['fee_mode'] ?? 'merchant_pays');
  if ($amount <= 0) return ['error' => 'positive_amount_kobo_required'];
  if (!in_array($mode, ['merchant_pays', 'customer_pays'], true)) return ['error' => 'invalid_fee_mode'];

  $platformFee = \App\Fee\FeePolicy::platformFeeKobo($amount);
  $vat = \App\Fee\FeePolicy::vatOnPlatformFeeKobo($platformFee);
  $providerFee = \App\Fee\FeePolicy::providerFeeKobo($amount);

  if ($mode === 'merchant_pays') {
    return [
      'fee_mode' => $mode,
      'amount_kobo' => $amount,
      'total_collected_kobo' => $amount,
      'merchant_net_kobo' => $amount - ($platformFee + $vat + $providerFee),
      'platform_fee_kobo' => $platformFee,
      'vat_kobo' => $vat,
      'provider_fee_kobo' => $providerFee,
    ];
  }

  return [
    'fee_mode' => $mode,
    'amount_kobo' => $amount,
    'total_collected_kobo' => $amount + ($platformFee + $vat + $providerFee),
    'merchant_net_kobo' => $amount,
    'platform_fee_kobo' => $platformFee,
    'vat_kobo' => $vat,
    'provider_fee_kobo' => $providerFee,
  ];
});

$router->add('POST', '/fees/rounding_demo', function ($body) {
  $amount = (int)($body['amount_kobo'] ?? 0);
  if ($amount <= 0) return ['error' => 'positive_amount_kobo_required'];

  $pf = \App\Fee\FeePolicy::platformFeeKobo($amount);
  $prov = \App\Fee\FeePolicy::providerFeeKobo($amount);
  $vat = \App\Fee\FeePolicy::vatOnPlatformFeeKobo($pf);

  return [
    'amount_kobo' => $amount,
    'platform_fee_kobo' => $pf,
    'provider_fee_kobo' => $prov,
    'vat_kobo' => $vat,
    'total_fees_kobo' => ($pf + $prov + $vat),
  ];
});

$router->add('POST', '/users', fn($body) => $app->createUser($body));
$router->add('POST', '/wallet/fund', fn($body) => $app->fundWallet($body));

$router->add('POST', '/transfers', function ($body) use ($app) {
  $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
  if (trim($key) === '') return ['error' => 'Idempotency-Key header required'];
  return $app->createTransfer($body, $key);
});

$router->add('POST', '/provider/transfer_webhook', fn($body) => $app->simulateTransferWebhook($body));
$router->add('POST', '/provider/process_transfers', fn() => $app->processTransferEvents());

$router->add('GET', '/transfers', function () use ($pdo) {
  $stmt = $pdo->query('SELECT * FROM transfers ORDER BY created_at DESC LIMIT 50');
  return ['transfers' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
});

$router->add('POST', '/provider/transfer_query', fn($body) => $app->providerQueryTransfer($body));

$router->add('POST', '/transfers/poll', fn($body) => $app->pollUnknownTransfers($body));
// End of file