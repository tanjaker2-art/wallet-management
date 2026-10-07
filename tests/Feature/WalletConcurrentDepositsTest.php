<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Jobs\CalculateRebate;
use Illuminate\Support\Facades\Bus;

class WalletConcurrentDepositsTest extends TestCase
{
    use RefreshDatabase;

    public function test_concurrent_deposits_with_rebate()
    {

        Bus::fake();

        $wallet = Wallet::create(['user_id' => 1, 'balance' => 0]);

        // Simulate two deposits occurring "concurrently" by running service methods in quick succession
        $tx1 = $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 100]);
        $tx2 = $this->postJson("/api/wallets/{$wallet->id}/deposit", ['amount' => 50]);

        $tx1->assertStatus(201);
        $tx2->assertStatus(201);

        // Process rebate jobs for both transactions by fetching deposit transactions and processing
        $deposits = Transaction::where('wallet_id', $wallet->id)->where('type', 'deposit')->get();
        foreach ($deposits as $d) {
            (new CalculateRebate($d))->handle();
        }

        $wallet->refresh();

        // Expected balance: 100 + 1 + 50 + 0.50 = 151.50
        $this->assertEquals('151.50', number_format($wallet->balance, 2, '.', ''));
    }
}
