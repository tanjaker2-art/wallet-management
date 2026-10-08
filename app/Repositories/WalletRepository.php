<?php

namespace App\Repositories;

use App\Models\Transaction;
use App\Models\Wallet;
use Closure;

interface WalletRepository
{
    public function transaction(Closure $callback): mixed;

    public function lockForUpdate(int $walletId): Wallet;

    public function save(Wallet $wallet): void;

    public function createTransaction(int $walletId, string $type, float $amount): Transaction;
}
