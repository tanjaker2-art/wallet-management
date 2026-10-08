# Project Flow

This document describes the high-level flow for wallet operations and where key components live in the codebase.

## Key components

- Models:
  - `App\Models\Wallet` — stores `user_id` and `balance`.
  - `App\Models\Transaction` — records `wallet_id`, `type` (`deposit`, `withdrawal`, `rebate`), `amount`, timestamps.

- Service:
  - `App\Services\WalletService` — encapsulates business logic for `deposit()` and `withdraw()` using DB transactions and row-level locks.

- Job:
  - `App\Jobs\CalculateRebate` — calculates the 1% rebate for deposit transactions and applies it to the wallet asynchronously.

- Controller / Routes:
  - `App\Http\Controllers\Api\WalletController` — API endpoints:
    - `GET /api/wallets/{wallet}` — show wallet and transactions
    - `POST /api/wallets/{wallet}/deposit` — deposit funds (queues rebate)
    - `POST /api/wallets/{wallet}/withdraw` — withdraw funds

## Request flow (deposit)

1. Client calls `POST /api/wallets/{wallet}/deposit` with an `amount`.
2. `WalletController::deposit()` validates the request and calls `WalletService::deposit()`.
3. `WalletService::deposit()` begins a DB transaction, locks the wallet row with `lockForUpdate()`, increments the balance, and creates a `deposit` transaction.
4. The service dispatches `CalculateRebate` before the surrounding transaction commits. With the configured database queue on the same database connection, the queued row becomes visible when that transaction commits.
5. The controller returns the deposit transaction with HTTP 201. The rebate may still be pending.
6. The queue worker picks up the job, locks the wallet row, credits a 1% rebate as a `rebate` transaction, and updates the wallet balance.

## Request flow (withdraw)

1. Client calls `POST /api/wallets/{wallet}/withdraw` with an `amount`.
2. `WalletController::withdraw()` validates and calls `WalletService::withdraw()`.
3. `WalletService::withdraw()` begins a DB transaction, locks the wallet row, checks sufficient balance, decrements balance, creates a `withdrawal` transaction, and commits.

## Transaction history

- Transaction history is available via `GET /api/wallets/{wallet}` — this returns the `wallet` record with its `transactions` relationship loaded.

## Tests

- `tests/Unit/WalletServiceTest.php` tests service rules with a mocked
  repository and bus dispatcher; it does not connect to a database.
- `tests/Integration/WalletApiTest.php` tests API persistence against a
  Testcontainers MySQL 9.6.0 instance using the project's migrations. Each
  test rolls back its database changes.
- `tests/Feature/ExampleTest.php` checks the web root smoke response.
- Run database-free unit tests with `php artisan test --testsuite=Unit`.
- Run MySQL integration tests with `php artisan test --testsuite=Integration`
  (Docker required).

## Files of interest

- `database/migrations/*` — migrations for `wallets` and `transactions`.
- `app/Services/WalletService.php` — core business logic.
- `app/Jobs/CalculateRebate.php` — rebate job.
- `routes/api.php` — API endpoints.
