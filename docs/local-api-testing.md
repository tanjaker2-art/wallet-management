# Local API and queue testing

This guide exercises the wallet API against a local database and processes the
deposit rebate with a real Laravel queue worker.

## What is running and configured

The API has three routes:

| Method | URL | Purpose |
| --- | --- | --- |
| `GET` | `/api/wallets/{wallet}` | Read wallet balance and transaction history |
| `POST` | `/api/wallets/{wallet}/deposit` | Deposit an amount and enqueue its rebate calculation |
| `POST` | `/api/wallets/{wallet}/withdraw` | Withdraw an amount |

The application is configured to use Laravel's `database` queue connection.
The `jobs` and `failed_jobs` tables are present in the local database, and
deposits dispatch `App\Jobs\CalculateRebate`. A worker is **not** started by
the web server; keep `php artisan queue:work` running in a separate terminal
to process rebate jobs. Without that worker, a deposit succeeds but its rebate
stays pending in the queue.

At the time this guide was written, the configured local database was reachable
and migrations were current, but it had no wallets, pending jobs, failed jobs,
or active queue worker. The steps below create a test wallet and start the
worker.

## 1. Prepare the application

Run these commands from the repository root in PowerShell:

```powershell
composer install
```

Make sure `.env` exists and has a valid database connection. The current local
setup uses MySQL; if yours does not, set `DB_CONNECTION`, `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` for your
local database. For this test, set the queue explicitly:

```dotenv
QUEUE_CONNECTION=database
```

If `APP_KEY` is blank on a first-time setup, generate it once:

```powershell
php artisan key:generate
```

Create or update the database schema:

```powershell
php artisan migrate
```

Create a test user and a fresh wallet with an opening balance of 1000.00. In
PowerShell, wrap the `--execute` PHP expression in **double quotes**. This
avoids PowerShell mangling the PHP expression's quotes and variables:

```powershell
php artisan tinker --execute="echo App\Models\Wallet::create(['user_id' => App\Models\User::factory()->create()->id, 'balance' => '1000.00'])->id;"
```

This prints the new wallet's numeric ID. Each time you run the command it
creates another test user and wallet; use the ID printed for your requests.
The API currently has no authentication middleware.

## 2. Start the API server

In the first PowerShell terminal, run:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Leave this process running. The base URL for the examples is
`http://127.0.0.1:8000`.

## 3. Start the queue worker

In a second PowerShell terminal, run:

```powershell
php artisan queue:work database --queue=default --tries=3 -v
```

Leave the worker running while sending requests. A successful job is removed
from the `jobs` table. **No output while idle is normal**: the worker waits for
jobs and only prints job-processing output when work arrives. After you send a
deposit request, look for the rebate job to be processed. The command should
remain active instead of returning to the PowerShell prompt. Stop the worker
with `Ctrl+C` when finished.

## 4. Test the API

Run the following in a third PowerShell terminal. Set `$walletId` to the ID
printed when you created the wallet:

```powershell
$walletId = 1
$baseUrl = "http://127.0.0.1:8000/api/wallets/$walletId"
```

### Read the starting wallet

```powershell
Invoke-RestMethod -Method Get -Uri $baseUrl
```

The response includes the wallet and its `transactions` array. The initial
balance should be `1000.00`.

### Deposit and verify the asynchronous rebate

```powershell
$body = @{ amount = 100.00 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$baseUrl/deposit" -ContentType "application/json" -Body $body
```

The deposit returns HTTP `201` and a transaction with `type: "deposit"` and
`amount: "100.00"`. The worker should then process `CalculateRebate`, which
credits 1% (`1.00`) and records a `rebate` transaction.

Read the wallet again after the worker has handled the job:

```powershell
Invoke-RestMethod -Method Get -Uri $baseUrl
```

The balance should be `1101.00`, with both the deposit and rebate in the
transaction history. If it is still `1100.00`, confirm the worker is running
against the same `.env` database and `default` queue, then check the pending and
failed job commands below.

### Withdraw

```powershell
$body = @{ amount = 25.00 } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$baseUrl/withdraw" -ContentType "application/json" -Body $body
```

The response is HTTP `201` with a `withdrawal` transaction for `25.00`. Read
the wallet again; after the rebate it should have a balance of `1076.00`.

### Check request validation

An amount must be numeric and at least `0.01`. This request should return
HTTP `422` with a validation error:

```powershell
$body = @{ amount = 0 } | ConvertTo-Json
try {
    Invoke-RestMethod -Method Post -Uri "$baseUrl/deposit" -ContentType "application/json" -Body $body
} catch {
    $_.Exception.Response.StatusCode
}
```

An unknown wallet ID should return HTTP `404`:

```powershell
try {
    Invoke-RestMethod -Method Get -Uri "http://127.0.0.1:8000/api/wallets/999999"
} catch {
    $_.Exception.Response.StatusCode
}
```

PowerShell's `Invoke-RestMethod` raises an exception for these non-2xx
responses; the `catch` blocks print the expected status code.

## 5. Inspect queued and failed jobs

With the app's configured database, these commands show jobs still waiting and
jobs that failed:

```powershell
php artisan tinker --execute='dump(DB::table("jobs")->count());'
php artisan queue:failed
```

When the worker is running, the pending count should return to zero after the
rebate finishes. Failed jobs are not expected for the normal deposit flow.

## Troubleshooting

- **Database connection error:** Check the database service and the `DB_*`
  settings in `.env`, then run `php artisan migrate --seed`.
- **Deposit succeeds but balance stays at 1100.00:** Ensure
  `QUEUE_CONNECTION=database`, the worker is running, and both terminals use
  the same project and `.env` file.
- **Missing `jobs` table:** Run `php artisan migrate`.
- **Failed rebate:** Inspect `php artisan queue:failed` and the Laravel log at
  `storage/logs/laravel.log`.
- **Validation request appears as HTML:** Keep `-ContentType
  "application/json"` on the POST commands so Laravel returns JSON errors.

Do not use `php artisan migrate:fresh` on a database containing data you want
to keep; it drops all tables.
