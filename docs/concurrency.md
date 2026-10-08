# Concurrency Handling

This document explains how the wallet system prevents race conditions and ensures accurate balance updates under concurrent requests.

## Strategies used

- Pessimistic locking (primary):
  - We use database transactions + `SELECT ... FOR UPDATE` via Eloquent's `lockForUpdate()` to obtain a row-level lock on the `wallets` record before updating the balance.
  - This ensures only one transaction updates the wallet balance at a time, preventing lost updates when deposits/withdrawals/rebate jobs run concurrently.

- Atomic DB transactions:
  - Balance updates and transaction inserts are executed inside a single DB transaction. If anything fails, the transaction rolls back, preserving consistency.

- Async rebate calculation:
  - Deposits create a `deposit` transaction and dispatch a `CalculateRebate` job (1% of deposit). The job also locks the wallet when applying the rebate to avoid races with other updates.

## Why pessimistic locking

- Works well for high-contention single-row updates (wallet balance). It serializes concurrent writers at the DB level, keeping business logic simple.
- It relies on database support for row locking. Integration tests use
  Testcontainers with MySQL 9.6.0 to match the configured production version,
  exercising locking behavior on the production database engine.

## Alternatives and enhancements

- Optimistic locking: add a `version` (or `updated_at`) column and use `WHERE version = ?` updates or a library to detect conflicts and retry. This is useful when conflicts are rare and you want higher concurrency.
- Use atomic DB expressions: perform balance changes with SQL `UPDATE wallets SET balance = balance + ? WHERE id = ?` to avoid reading-then-writing if your DB driver supports precise decimal arithmetic.
- Choose queue driver carefully: use a reliable queue (Redis, database, or external queue) and run `php artisan queue:work` to process rebate jobs. Unit tests mock the repository and dispatcher without a database; integration tests use MySQL Testcontainers and manually handle rebate jobs.

## Operational notes

- Always run the queue worker in production (`php artisan queue:work` or a supervisor).
- Monitor job failures and requeues.
- If you need extremely high throughput per wallet consider sharding wallets across accounts or switching to optimistic approaches with retries.
