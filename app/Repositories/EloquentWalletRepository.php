<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Models\Wallet;
use Closure;
use Illuminate\Database\DatabaseManager;

class EloquentWalletRepository implements WalletRepository
{
    public function __construct(private DatabaseManager $db) {}

    public function transaction(Closure $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    public function lockForUpdate(int $walletId): Wallet
    {
        return Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    public function save(Wallet $wallet): void
    {
        $wallet->save();
    }

    public function createTransaction(int $walletId, string $type, float $amount): Transaction
    {
        return Transaction::create([
            'wallet_id' => $walletId,
            'type' => $type,
            'amount' => $amount,
        ]);
    }
}
