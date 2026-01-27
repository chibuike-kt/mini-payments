PRAGMA foreign_keys = ON;

-- Merchants
CREATE TABLE merchants (
  id TEXT PRIMARY KEY,
  name TEXT NOT NULL
);

-- Payment intents (your internal payment object)
CREATE TABLE payment_intents (
  id TEXT PRIMARY KEY,
  merchant_id TEXT NOT NULL,
  amount_kobo INTEGER NOT NULL,
  currency TEXT NOT NULL,
  status TEXT NOT NULL, -- created | succeeded | failed
  idempotency_key TEXT NOT NULL,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

-- Provider events (webhooks) - dedupe by provider_event_id
CREATE TABLE provider_events (
  id TEXT PRIMARY KEY,
  provider_event_id TEXT NOT NULL UNIQUE,
  type TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  processed INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);

-- Idempotency keys
CREATE TABLE idempotency_keys (
  id TEXT PRIMARY KEY,
  key TEXT NOT NULL UNIQUE,
  request_hash TEXT NOT NULL,
  response_json TEXT NOT NULL,
  created_at TEXT NOT NULL
);

-- Chart of Accounts (this is what makes accounting correct)
-- type decides normal balance direction.
CREATE TABLE accounts (
  account TEXT PRIMARY KEY,
  type TEXT NOT NULL -- ASSET | LIABILITY | REVENUE | EXPENSE
);

-- Journals group postings (one logical money event)
CREATE TABLE journals (
  id TEXT PRIMARY KEY,
  ref_type TEXT NOT NULL,   -- payment | release | settlement
  ref_id TEXT NOT NULL,     -- payment_intent_id or settlement_id
  memo TEXT NOT NULL,
  created_at TEXT NOT NULL
);

-- Postings are the actual debits/credits
CREATE TABLE postings (
  id TEXT PRIMARY KEY,
  journal_id TEXT NOT NULL,
  account TEXT NOT NULL,
  dc TEXT NOT NULL, -- D or C
  amount_kobo INTEGER NOT NULL,
  created_at TEXT NOT NULL,
  FOREIGN KEY (journal_id) REFERENCES journals(id),
  FOREIGN KEY (account) REFERENCES accounts(account)
);

-- Settlements
CREATE TABLE settlements (
  id TEXT PRIMARY KEY,
  merchant_id TEXT NOT NULL,
  amount_kobo INTEGER NOT NULL,
  currency TEXT NOT NULL,
  status TEXT NOT NULL, -- created | paid | failed
  created_at TEXT NOT NULL,
  FOREIGN KEY (merchant_id) REFERENCES merchants(id)
);

-- A view that computes balances from postings + account type.
-- This is the key upgrade: "balance derived from ledger", not cached math.
CREATE VIEW v_balances AS
SELECT
  a.account,
  a.type,
  COALESCE(SUM(
    CASE
      WHEN a.type IN ('ASSET','EXPENSE') THEN
        CASE WHEN p.dc='D' THEN p.amount_kobo ELSE -p.amount_kobo END
      WHEN a.type IN ('LIABILITY','REVENUE') THEN
        CASE WHEN p.dc='C' THEN p.amount_kobo ELSE -p.amount_kobo END
      ELSE 0
    END
  ), 0) AS balance_kobo
FROM accounts a
LEFT JOIN postings p ON p.account = a.account
GROUP BY a.account, a.type;
