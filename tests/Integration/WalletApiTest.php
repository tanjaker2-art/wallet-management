<?php

namespace Tests\Integration;

use App\Jobs\CalculateRebate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\MySQLContainer;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    private static ?StartedGenericContainer $mysql = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$mysql = (new MySQLContainer('9.6.0'))
            ->withMySQLDatabase('wallet_test')
            ->withMySQLUser('wallet_test', 'wallet_test_password')
            ->start();

        self::setTestEnvironment('DB_CONNECTION', 'mysql');
        self::setTestEnvironment('DB_HOST', self::$mysql->getHost());
        self::setTestEnvironment('DB_PORT', (string) self::$mysql->getFirstMappedPort());
        self::setTestEnvironment('DB_DATABASE', 'wallet_test');
        self::setTestEnvironment('DB_USERNAME', 'wallet_test');
        self::setTestEnvironment('DB_PASSWORD', 'wallet_test_password');
        self::setTestEnvironment('DB_URL', '');
        self::setTestEnvironment('QUEUE_CONNECTION', 'sync');
    }

    public static function tearDownAfterClass(): void
    {
        self::$mysql?->stop();
        self::$mysql = null;

        parent::tearDownAfterClass();
    }

    public function test_deposit_dispatches_rebate_and_persists_it_in_mysql(): void
    {
        Bus::fake();
        $wallet = $this->createWallet('0.00');

        $response = $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 100]);

        $response->assertCreated()->assertJsonPath('type', 'deposit');
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'deposit',
            'amount' => '100.00',
        ]);
        Bus::assertDispatched(CalculateRebate::class);

        $deposit = Transaction::where('wallet_id', $wallet->id)->where('type', 'deposit')->firstOrFail();
        (new CalculateRebate($deposit))->handle();

        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'rebate',
            'amount' => '1.00',
        ]);
        $this->assertSame('101.00', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
    }

    public function test_sequential_deposits_and_rebates_preserve_the_expected_balance(): void
    {
        Bus::fake();
        $wallet = $this->createWallet('0.00');

        $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 100])->assertCreated();
        $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 50])->assertCreated();

        Transaction::where('wallet_id', $wallet->id)
            ->where('type', 'deposit')
            ->get()
            ->each(fn (Transaction $deposit) => (new CalculateRebate($deposit))->handle());

        $this->assertSame('151.50', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
    }

    public function test_withdrawal_debits_wallet_and_records_transaction(): void
    {
        $wallet = $this->createWallet('100.00');

        $this->postJson("/api/wallets/{$wallet->id}/withdraw", ['amount' => 25])
            ->assertCreated()
            ->assertJsonPath('type', 'withdrawal');

        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'withdrawal',
            'amount' => '25.00',
        ]);
        $this->assertSame('75.00', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
    }

    public function test_wallet_history_endpoint_returns_transactions(): void
    {
        $wallet = $this->createWallet('0.00');
        $wallet->transactions()->create(['type' => 'deposit', 'amount' => '12.50']);

        $this->getJson("/api/wallets/{$wallet->id}")
            ->assertOk()
            ->assertJsonPath('transactions.0.type', 'deposit')
            ->assertJsonPath('transactions.0.amount', '12.50');
    }

    private function createWallet(string $balance): Wallet
    {
        return Wallet::create([
            'user_id' => User::factory()->create()->id,
            'balance' => $balance,
        ]);
    }

    private static function setTestEnvironment(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
