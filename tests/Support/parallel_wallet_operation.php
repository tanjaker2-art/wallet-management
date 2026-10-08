<?php

use App\Jobs\CalculateRebate;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $walletId, $amount, $barrierDirectory, $workerId] = $argv;

if (! in_array($operation, ['deposit', 'withdraw'], true)) {
    fwrite(STDERR, "Unsupported wallet operation: {$operation}\n");
    exit(2);
}

$readyFile = $barrierDirectory.DIRECTORY_SEPARATOR.'ready-'.$workerId;
$startFile = $barrierDirectory.DIRECTORY_SEPARATOR.'start';

if (file_put_contents($readyFile, 'ready') === false) {
    fwrite(STDERR, "Unable to signal readiness for worker {$workerId}.\n");
    exit(2);
}

$deadline = microtime(true) + 20;
while (! file_exists($startFile)) {
    if (microtime(true) >= $deadline) {
        fwrite(STDERR, "Timed out waiting for the wallet operation start barrier.\n");
        exit(2);
    }

    usleep(10_000);
}

if ($operation === 'deposit') {
    Bus::fake([CalculateRebate::class]);
}

try {
    $wallet = Wallet::findOrFail((int) $walletId);
    $service = $app->make(WalletService::class);
    $transaction = $service->{$operation}($wallet, (float) $amount);

    echo json_encode(['outcome' => 'success', 'transaction_id' => $transaction->id], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Exception $exception) {
    if ($operation === 'withdraw' && $exception->getMessage() === 'Insufficient funds') {
        echo json_encode(['outcome' => 'insufficient_funds'], JSON_THROW_ON_ERROR);
        exit(0);
    }

    throw $exception;
}
