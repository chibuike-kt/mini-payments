*Engineering note:*
This codebase prioritizes correctness, accounting integrity, and failure handling over feature completeness.

*Project Overview*

This repository implements a wallet-first payment infrastructure core, inspired by how modern Nigerian fintechs (e.g. OPay-style systems) handle bank transfers, ledger consistency, and asynchronous settlement.

It is not a consumer product.
It is an engineering sandbox focused on correctness, accounting truth, and failure handling.

*Core Concepts Implemented*
This system intentionally models real payment primitives:
- Double-entry ledger as the source of truth
- Wallet liabilities vs platform settlement assets
- Transfer holds (funds reserved until external finality)
- Idempotent APIs (safe retries)
- Asynchronous provider events
- Polling + SLA-based escalation
- Failed-but-debited bank transfer handling
- Fee calculation with explicit rounding rules
- Deterministic unit tests enforcing invariants
- Bank Transfer Design (OPay-style)
- Transfers follow a wallet-first model:
- User wallet is debited immediately into a transfer hold
- External bank transfer is submitted asynchronously
- Final state is determined via:
- provider callbacks
- polling
- reconciliation logic

*Supported States*
created
→ wallet_held
→ submitted | unknown
→ credit_confirmed
→ failed_no_debit
→ failed_debited
→ reversal_initiated
→ reversed
→ manual_review

N.B: The system never assumes finality on timeouts.

*Failed-but-Debited Handling (Variant 1)*
When a transfer fails but the sender bank has already debited funds:

- User wallet is NOT refunded immediately
- Funds remain locked in a transfer hold
- Reversal is awaited asynchronously
- Wallet refund occurs only after reversal confirmation
- This mirrors conservative, risk-aware fintech operations.
- Ledger Invariants (Guaranteed by Tests)

*The system enforces:*
- Every journal entry must balance
- Wallet + holds + revenue always reconcile
- Fees + rounding never create or destroy money
- Unknown transfers never silently fail
- Unit tests exist specifically to protect these invariants.

*Why This Exists*
This project exists to demonstrate foundational fintech engineering knowledge, not surface-level API usage.

*It focuses on:*
- money movement correctness
- failure modeling
- accounting truth
- operational realism

*Disclaimer*

This is not a licensed financial system and should not be used in production.
External bank/provider behavior is simulated for learning purposes.