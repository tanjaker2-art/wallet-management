<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $service) {}

    public function show(Wallet $wallet)
    {
        $wallet->load('transactions');
        return response()->json($wallet);
    }

    public function deposit(Request $request, Wallet $wallet)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01']);
        $tx = $this->service->deposit($wallet, (float)$data['amount']);
        return response()->json($tx, 201);
    }

    public function withdraw(Request $request, Wallet $wallet)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01']);
        $tx = $this->service->withdraw($wallet, (float)$data['amount']);
        return response()->json($tx, 201);
    }
}
