<?php

namespace Tests\Unit;

use App\Jobs\CalculateRebate;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Repositories\WalletRepository;
use App\Services\WalletService;
use Illuminate\Contracts\Bus\Dispatcher;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class WalletServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_deposit_updates_balance_creates_transaction_and_dispatches_rebate(): void
    {
        $wallet = new Wallet(['balance' => '100.00']);
        $wallet->id = 7;
        $transaction = new Transaction(['wallet_id' => 7, 'type' => 'deposit', 'amount' => 25.00]);
        $repository = Mockery::mock(WalletRepository::class);
        $bus = Mockery::mock(Dispatcher::class);

        $repository->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());
        $repository->shouldReceive('lockForUpdate')->once()->with(7)->andReturn($wallet);
        $repository->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn (Wallet $updated) => $updated->balance === '125.00'));
        $repository->shouldReceive('createTransaction')
            ->once()
            ->with(7, 'deposit', 25.00)
            ->andReturn($transaction);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn ($job) => $job instanceof CalculateRebate && $job->transaction === $transaction));

        $result = (new WalletService($repository, $bus))->deposit($wallet, 25.00);

        $this->assertSame($transaction, $result);
    }

    public function test_withdrawal_updates_balance_and_records_transaction(): void
    {
        $wallet = new Wallet(['balance' => '100.00']);
        $wallet->id = 7;
        $transaction = new Transaction(['wallet_id' => 7, 'type' => 'withdrawal', 'amount' => 40.00]);
        $repository = Mockery::mock(WalletRepository::class);
        $bus = Mockery::mock(Dispatcher::class);

        $repository->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());
        $repository->shouldReceive('lockForUpdate')->once()->with(7)->andReturn($wallet);
        $repository->shouldReceive('save')
            ->once()
            ->with(Mockery::on(fn (Wallet $updated) => $updated->balance === '60.00'));
        $repository->shouldReceive('createTransaction')
            ->once()
            ->with(7, 'withdrawal', 40.00)
            ->andReturn($transaction);
        $bus->shouldNotReceive('dispatch');

        $result = (new WalletService($repository, $bus))->withdraw($wallet, 40.00);

        $this->assertSame($transaction, $result);
    }

    public function test_withdrawal_rejects_insufficient_funds_without_writing(): void
    {
        $wallet = new Wallet(['balance' => '10.00']);
        $wallet->id = 7;
        $repository = Mockery::mock(WalletRepository::class);
        $bus = Mockery::mock(Dispatcher::class);

        $repository->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(fn ($callback) => $callback());
        $repository->shouldReceive('lockForUpdate')->once()->with(7)->andReturn($wallet);
        $repository->shouldNotReceive('save');
        $repository->shouldNotReceive('createTransaction');
        $bus->shouldNotReceive('dispatch');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient funds');

        (new WalletService($repository, $bus))->withdraw($wallet, 11.00);
    }
}
