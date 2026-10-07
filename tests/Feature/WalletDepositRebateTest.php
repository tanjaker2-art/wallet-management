<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\Bus;
use App\Jobs\CalculateRebate;

class WalletDepositRebateTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_creates_rebate_job_and_credits_rebate()
    {
        Bus::fake();

        $wallet = Wallet::create(['user_id' => 1, 'balance' => 0]);

        $response = $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 100]);
        $response->assertStatus(201);

        // Ensure a transaction was created
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'deposit',
            'amount' => '100.00',
        ]);

        // Rebate job should be dispatched
        Bus::assertDispatched(CalculateRebate::class);

        // Now process the job synchronously and assert rebate applied
        $tx = Transaction::where('wallet_id', $wallet->id)->where('type', 'deposit')->first();
        (new CalculateRebate($tx))->handle();

        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'rebate',
            'amount' => '1.00',
        ]);

        $wallet->refresh();
        $this->assertEquals('101.00', number_format($wallet->balance, 2, '.', ''));
    }
}
