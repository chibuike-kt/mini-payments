-- Track disputes/chargebacks on a payment intent.
CREATE TABLE disputes (
  id TEXT PRIMARY KEY,
  payment_intent_id TEXT NOT NULL,
  type TEXT NOT NULL,        -- chargeback | refund
  amount_kobo INTEGER NOT NULL,
  currency TEXT NOT NULL,
  status TEXT NOT NULL,      -- opened | closed
  reason TEXT NOT NULL,
  created_at TEXT NOT NULL,
  closed_at TEXT,
  FOREIGN KEY (payment_intent_id) REFERENCES payment_intents(id)
);

-- Track refunds/chargebacks processing idempotently at our side too.
CREATE TABLE reversals (
  id TEXT PRIMARY KEY,
  payment_intent_id TEXT NOT NULL UNIQUE, -- only 1 reversal per payment in this demo
  reversal_type TEXT NOT NULL,            -- chargeback
  created_at TEXT NOT NULL,
  FOREIGN KEY (payment_intent_id) REFERENCES payment_intents(id)
);
