<?php

namespace App\Services;

use App\Models\Wallet;
use App\Jobs\CalculateRebate;
use App\Models\Transaction;
use App\Repositories\WalletRepository;
use Illuminate\Contracts\Bus\Dispatcher;

class WalletService
{
    public function __construct(
        private WalletRepository $wallets,
        private Dispatcher $bus,
    ) {}

    public function deposit(Wallet $wallet, float $amount): Transaction
    {
        return $this->wallets->transaction(function () use ($wallet, $amount) {
            // Pessimistic lock to avoid race conditions
            $wallet = $this->wallets->lockForUpdate($wallet->id);
            $wallet->balance = bcadd($wallet->balance, (string)$amount, 2);
            $this->wallets->save($wallet);

            $tx = $this->wallets->createTransaction($wallet->id, 'deposit', $amount);

            // Queue rebate calculation
            $this->bus->dispatch(new CalculateRebate($tx));

            return $tx;
        });
    }

    public function withdraw(Wallet $wallet, float $amount): Transaction
    {
        return $this->wallets->transaction(function () use ($wallet, $amount) {
            $wallet = $this->wallets->lockForUpdate($wallet->id);
            if (bccomp($wallet->balance, (string)$amount, 2) < 0) {
                throw new \Exception('Insufficient funds');
            }
            $wallet->balance = bcsub($wallet->balance, (string)$amount, 2);
            $this->wallets->save($wallet);

            return $this->wallets->createTransaction($wallet->id, 'withdrawal', $amount);
        });
    }
}
