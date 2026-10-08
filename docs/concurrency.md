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
- It relies on database support for row locking. Integration tests use the
  dedicated local MySQL 9.6.0 `wallet_management_test` database to match the
  configured production engine.

## Alternatives and enhancements

- Optimistic locking: add a `version` (or `updated_at`) column and use `WHERE version = ?` updates or a library to detect conflicts and retry. This is useful when conflicts are rare and you want higher concurrency.
- Use atomic DB expressions: perform balance changes with SQL `UPDATE wallets SET balance = balance + ? WHERE id = ?` to avoid reading-then-writing if your DB driver supports precise decimal arithmetic.
- Choose queue driver carefully: use a reliable queue (Redis, database, or external queue) and run `php artisan queue:work` to process rebate jobs. Unit tests mock the repository and dispatcher without a database; integration tests use local MySQL and manually handle rebate jobs.

## Operational notes

- Always run the queue worker in production (`php artisan queue:work` or a supervisor).
- Monitor job failures and requeues.
- If you need extremely high throughput per wallet consider sharding wallets across accounts or switching to optimistic approaches with retries.

## Concurrency integration tests

`tests/Integration/WalletConcurrencyTest.php` starts three independent PHP
processes, waits until they are all ready, then releases them together against
the same wallet in the dedicated local MySQL test database. This exercises
separate MySQL connections and the row lock used by `WalletService`.

- Three simultaneous withdrawals of `80.00` against a `100.00` balance must
  produce exactly one withdrawal, two insufficient-funds outcomes, and a
  final balance of `20.00`.
- Three simultaneous deposits of `80.00` against a zero balance must create
  three deposit transactions and leave a `240.00` balance. The subprocesses
  fake rebate dispatch so this test isolates concurrent balance updates;
  asynchronous rebate execution is covered separately.

Run these tests with `composer test:integration`. They use
`DatabaseMigrations`, rather than a wrapping test transaction, so child
processes can see the setup data and the parent can assert their committed
results. The migration-reset behavior is limited to the guarded
`wallet_management_test` schema.
