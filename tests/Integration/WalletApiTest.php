<?php

namespace Tests\Integration;

use App\Jobs\CalculateRebate;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

class WalletApiTest extends MySqlIntegrationTestCase
{
    use RefreshDatabase;

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
}
