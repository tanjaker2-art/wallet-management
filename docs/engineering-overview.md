# Engineering overview

This document explains how the Laravel application boots, how wallet requests
move through the system, which consistency measures are implemented, and what
the current tests do and do not prove. It describes the repository as it
currently exists; it is not a claim that the API is production-hardened.

## 1. Application startup

The project is a Laravel 13 application requiring PHP 8.3 or later. Composer
dependencies and application commands are defined in `composer.json`.

At runtime, `artisan` boots the application using `bootstrap/app.php`. The
bootstrap file registers:

- Web routes from `routes/web.php`.
- API routes from `routes/api.php`, which Laravel exposes under `/api`.
- Console commands from `routes/console.php`.
- The `/up` health endpoint.
- JSON exception rendering for API requests.

The API routes point to `App\Http\Controllers\Api\WalletController`.
`AppServiceProvider` binds `WalletService` into Laravel's service container,
allowing the controller to receive the service through constructor injection.

### Local startup

From the repository root, install dependencies and create `.env` from
`.env.example` only if it does not already exist:

```powershell
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
```

Configure `.env` with a valid database connection. On a first-time setup only,
generate an application key if `APP_KEY` is empty:

```powershell
php artisan key:generate
```

Then run migrations and start the HTTP server:

```powershell
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Do not replace an existing key that protects data already encrypted with it.
The sample environment file uses MySQL. If using MySQL, create the database
and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`
appropriately before migrating. Do not point local experiments at a database
containing data you need to preserve.

The default queue connection is `database` (`config/queue.php`), and its
`jobs`, `job_batches`, and `failed_jobs` tables are included in migrations. Run
a separate worker to process deposit rebates:

```powershell
php artisan queue:work database --queue=default --tries=3 -v
```

An idle worker normally prints nothing while it waits. A worker is a separate
long-lived process; starting the web server does not start one. See
[Local API and queue testing](local-api-testing.md) for complete API setup and
Postman/PowerShell examples.

## 2. Request lifecycle

### Read a wallet

`GET /api/wallets/{wallet}` is matched in `routes/api.php`. Laravel resolves
the `{wallet}` parameter to a `Wallet` model using implicit route model
binding. `WalletController::show()` loads the wallet's transactions and returns
the wallet as JSON. An unknown ID returns 404.

### Deposit

1. The client sends `POST /api/wallets/{wallet}/deposit` with a JSON `amount`.
2. The controller validates that the amount is present, numeric, and at least
   `0.01`. Invalid input is returned as a JSON validation response (422 for a
   JSON request).
3. `WalletService::deposit()` begins a database transaction and retrieves the
   wallet row with `lockForUpdate()`.
4. The service increments the balance using BCMath at two decimal places and
   inserts a `deposit` transaction.
5. It dispatches `CalculateRebate` for that transaction. The queue driver
   controls whether the job is handled immediately or stored for a worker.
6. The controller returns the deposit transaction with HTTP 201. This response
   does not mean an asynchronous rebate has completed.
7. With the database queue and a running worker, `CalculateRebate::handle()`
   ignores non-deposit transactions, looks up the wallet, calculates 1% of the
   deposit to two decimal places, and in its own database transaction locks
   and updates the wallet and records a `rebate` transaction.
8. A subsequent `GET` shows the new balance and transaction history.

For example, a deposit of `100.00` credits `100.00` immediately and queues a
`1.00` rebate. After the job succeeds, the total increase is `101.00`.

### Withdrawal

1. The client sends `POST /api/wallets/{wallet}/withdraw` with a JSON `amount`.
2. The controller applies the same required/numeric/minimum validation.
3. `WalletService::withdraw()` starts a database transaction, locks the
   wallet row, and compares the balance with the requested amount.
4. If funds are sufficient, the service subtracts the amount and inserts a
   `withdrawal` transaction; the controller returns it with HTTP 201.
5. If funds are insufficient, the service throws an exception before making
   changes. The current controller does not translate this exception into a
   specific API response, so clients should not rely on a defined
   insufficient-funds status or response shape yet.

## 3. Data model and persistence

| Table | Purpose | Relevant fields |
| --- | --- | --- |
| `users` | Laravel user records | `id`, `name`, `email`, credentials |
| `wallets` | Wallet state | `id`, `user_id`, `balance` (`DECIMAL(15,2)`) |
| `transactions` | Wallet ledger entries | `id`, `wallet_id`, `type`, `amount` (`DECIMAL(15,2)`) |
| `jobs` | Pending database queue work | Laravel queue payload and reservation fields |
| `failed_jobs` | Jobs that exhausted retry attempts | Laravel failure payload and exception |
| `job_batches` | Queue batch metadata | Batch counts and options |

The `Wallet` model has a `transactions` one-to-many relationship, and
`Transaction` belongs to a wallet. Current wallet and transaction migrations
use integer ID columns but do not declare foreign-key constraints. They also
do not add database indexes for `wallets.user_id` or `transactions.wallet_id`.
These are relevant schema decisions to revisit before production-scale use.

The application stores current balance on the wallet and also records deposits,
withdrawals, and rebates as transactions. The code does not currently derive
or reconcile the wallet balance from the ledger.

## 4. Consistency measures and operational precautions

### Implemented

- **Database transactions:** A balance update and its corresponding ledger
  insert are grouped together for deposits, withdrawals, and rebate
  application. A database error in that unit rolls back both changes.
- **Pessimistic row locks:** The service and rebate job use `lockForUpdate()`
  while changing wallet balances to serialize competing updates to that
  wallet. Row-lock guarantees depend on the production database engine.
- **Decimal storage and BCMath:** Balances and transaction amounts use
  `DECIMAL(15,2)`, and the balance arithmetic uses BCMath at scale 2. The PHP
  `bcmath` extension must be installed. The controller currently converts the
  validated request amount to a PHP float before passing it to the service;
  using decimal strings end-to-end would avoid introducing binary floating
  point before decimal arithmetic.
- **Input minimum and required checks:** The endpoints reject missing,
  nonnumeric, and less-than-`0.01` amounts. More business limits are not
  currently enforced by validation.
- **Queue failure recording:** The configured queue uses the database-backed
  failed-jobs table. Operators should monitor it with `php artisan queue:failed`
  and inspect `storage/logs/laravel.log`.
- **Missing wallet during rebate:** The job logs a warning and exits if it
  cannot find the wallet.

### Important limitations

- **No API authentication or authorization is configured.** Wallet endpoints
  are currently accessible without a logged-in user and do not check that a
  caller owns the wallet. Do not expose this API to an untrusted network.
- **Insufficient-funds errors are not mapped to an API contract.** The
  exception should be handled and tested before clients depend on its status
  code or message.
- **No idempotency key or duplicate-job guard exists.** Replaying a deposit
  request creates another deposit and rebate. The rebate job itself has no
  explicit idempotency protection if it is ever executed more than once.
- **Queue dispatch is not explicitly after-commit.** The database queue is
  configured with `after_commit => false`. The default database queue normally
  shares the application's database connection; if queue storage is moved to a
  different connection or driver, verify dispatch/commit ordering and consider
  after-commit dispatching so the worker cannot read an uncommitted transaction.
- **Amounts have no explicit maximum or precision validation in the request.**
  Confirm the business maximum and supported decimal precision, then validate
  them before relying on the database column as the only limit.
- **No foreign keys or ledger reconciliation are implemented.** Database
  constraints and a reconciliation strategy are worth adding before relying on
  ledger integrity in production.

## 5. Tests and what they establish

Run all tests from the repository root:

```powershell
php artisan test
```

Run only database-free unit tests:

```powershell
php artisan test --testsuite=Unit
```

Run MySQL integration tests (requires the local MySQL service and a dedicated
test schema):

```powershell
if (-not (Test-Path .env.testing)) { Copy-Item .env.testing.example .env.testing }
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wallet_management_test"
composer test:integration
```

Unit tests use PHPUnit directly and mock `WalletRepository` and the bus
dispatcher; they do not boot Laravel or connect to any database. Integration
tests use the existing local MySQL server and connection credentials in
`.env.testing`. `phpunit.xml` pins the schema to
`wallet_management_test`, and the integration test refuses to run against any
other database. Laravel's `RefreshDatabase` runs the project's real migrations
against that schema and rolls back each test's data changes. Do not configure
these credentials to target a production server.

### Current test inventory

| Test | Type | What it checks |
| --- | --- | --- |
| `tests/Unit/WalletServiceTest.php` | Unit | With mocked persistence and bus dependencies, checks deposit arithmetic/transaction/job dispatch, withdrawal arithmetic/transaction creation, and rejection of insufficient funds without writes. It opens no database connection. |
| `tests/Integration/WalletApiTest.php` | Integration | Against local MySQL 9.6.0 and the production migrations, checks deposit plus rebate persistence, sequential deposits and rebates, withdrawal persistence, and wallet transaction history. `RefreshDatabase` rolls each test back. |
| `tests/Integration/WalletConcurrencyTest.php` | Integration | Starts independent PHP processes behind a shared start barrier to issue same-wallet withdrawals and deposits at the same time against local MySQL. Checks that only one of three $80 withdrawals from a $100 wallet succeeds, and all three simultaneous $80 deposits are reflected. Uses `DatabaseMigrations` to reset the dedicated test schema around each test. |
| `tests/Feature/ExampleTest.php` | Feature smoke test | The web root returns HTTP 200. |
| `tests/Unit/ExampleTest.php` | Unit placeholder | Asserts `true`; it does not exercise application code. |

The integration tests fake queue dispatch and invoke
`CalculateRebate::handle()` directly; the concurrency deposit subprocesses fake
rebate dispatch so those tests isolate balance locking and do not verify the
asynchronous queue worker or retry/failure behavior. The suite also does not
exercise API validation errors or authorization. Add those cases as
requirements are defined.

## 6. Useful operator commands

```powershell
# Inspect registered API endpoints
php artisan route:list --path=api

# Inspect whether migrations have run
php artisan migrate:status

# Start a worker with visible job output
php artisan queue:work database --queue=default --tries=3 -v

# List failed jobs
php artisan queue:failed

# Run the full suite
php artisan test
```

`php artisan migrate:fresh` drops and recreates every table. Do not run it
against any database whose contents must be retained.
