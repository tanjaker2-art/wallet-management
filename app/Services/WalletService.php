<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Jobs\CalculateRebate;
use Illuminate\Database\DatabaseManager;

class WalletService
{
    public function __construct(protected DatabaseManager $db) {}

    public function deposit(Wallet $wallet, float $amount): Transaction
    {
        return $this->db->transaction(function () use ($wallet, $amount) {
            // Pessimistic lock to avoid race conditions
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            $wallet->balance = bcadd($wallet->balance, (string)$amount, 2);
            $wallet->save();

            $tx = Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $amount,
            ]);

            // Queue rebate calculation
            CalculateRebate::dispatch($tx);

            return $tx;
        });
    }

    public function withdraw(Wallet $wallet, float $amount): Transaction
    {
        return $this->db->transaction(function () use ($wallet, $amount) {
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            if (bccomp($wallet->balance, (string)$amount, 2) < 0) {
                throw new \Exception('Insufficient funds');
            }
            $wallet->balance = bcsub($wallet->balance, (string)$amount, 2);
            $wallet->save();

            return Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'withdrawal',
                'amount' => $amount,
            ]);
        });
    }
}
