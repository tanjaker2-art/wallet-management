<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;

Route::get('/wallets/{wallet}', [WalletController::class, 'show']);
Route::post('/wallets/{wallet}/deposit', [WalletController::class, 'deposit']);
Route::post('/wallets/{wallet}/withdraw', [WalletController::class, 'withdraw']);
