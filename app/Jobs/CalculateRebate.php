<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CalculateRebate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Transaction $transaction) {}

    public function handle()
    {
        // Only process deposit transactions
        if ($this->transaction->type !== 'deposit') {
            return;
        }

        $wallet = Wallet::find($this->transaction->wallet_id);
        if (! $wallet) {
            Log::warning('Wallet not found for rebate', ['wallet_id' => $this->transaction->wallet_id]);
            return;
        }

        $rebate = bcmul($this->transaction->amount, '0.01', 2);

        // Persist rebate as a transaction and update wallet balance with a lock
        \DB::transaction(function () use ($wallet, $rebate) {
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            $wallet->balance = bcadd($wallet->balance, (string)$rebate, 2);
            $wallet->save();

            \App\Models\Transaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'rebate',
                'amount' => $rebate,
            ]);
        });
    }
}
