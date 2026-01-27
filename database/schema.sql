-- We keep it small but “real”.

-- Merchants receive money.
CREATE TABLE merchants (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL
);

-- Payment intents are requests to collect money.
CREATE TABLE payment_intents (
  id TEXT PRIMARY KEY,
  merchant_id TEXT NOT NULL,
  amount_kobo INTEGER NOT NULL,
  currency TEXT NOT NULL,
  status TEXT NOT NULL, -- created | processing | succeeded | failed
  idempotency_key TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);

-- Webhook events from the provider.
CREATE TABLE provider_events (
  id TEXT PRIMARY KEY,
  provider_event_id TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  processed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

-- Double-entry ledger.
CREATE TABLE ledger_entries (
  id TEXT PRIMARY KEY,
  ref_type TEXT NOT NULL,   -- payment_intent | settlement
  ref_id TEXT NOT NULL,     -- the id in that table
  account TEXT NOT NULL,    -- platform_cash | merchant_payable:{merchantId}
  direction TEXT NOT NULL,  -- debit | credit
  amount_kobo INTEGER NOT NULL,
  created_at TEXT NOT NULL
);

-- A cached balance table (derived from ledger, but kept for speed).
CREATE TABLE balances (
  account TEXT PRIMARY KEY,
  balance_kobo INTEGER NOT NULL
);

-- Used to enforce idempotency.
CREATE TABLE idempotency_keys (
  id TEXT PRIMARY KEY,
  key TEXT NOT NULL UNIQUE,
  request_hash TEXT NOT NULL,
  response_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);
