<?php

namespace Tests\Integration;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;

class WalletConcurrencyTest extends MySqlIntegrationTestCase
{
    use DatabaseMigrations;

    public function test_concurrent_withdrawals_only_one_succeeds(): void
    {
        $wallet = $this->createWallet('100.00');
        $results = $this->runConcurrentOperations('withdraw', $wallet->id, 80.00, 3);

        $this->assertSame(1, count(array_filter($results, fn (array $result) => $result['outcome'] === 'success')));
        $this->assertSame(2, count(array_filter($results, fn (array $result) => $result['outcome'] === 'insufficient_funds')));
        $this->assertSame('20.00', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
        $this->assertSame(1, Transaction::where('wallet_id', $wallet->id)->where('type', 'withdrawal')->count());
    }

    public function test_concurrent_deposits_preserve_all_balance_updates(): void
    {
        $wallet = $this->createWallet('0.00');
        $results = $this->runConcurrentOperations('deposit', $wallet->id, 80.00, 3);

        $this->assertCount(3, array_filter($results, fn (array $result) => $result['outcome'] === 'success'));
        $this->assertSame('240.00', number_format((float) $wallet->fresh()->balance, 2, '.', ''));
        $this->assertSame(3, Transaction::where('wallet_id', $wallet->id)->where('type', 'deposit')->count());
    }

    /**
     * @return list<array{outcome: string, message?: string}>
     */
    private function runConcurrentOperations(string $operation, int $walletId, float $amount, int $processCount): array
    {
        $barrierDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wallet-concurrency-'.bin2hex(random_bytes(12));
        if (! mkdir($barrierDirectory)) {
            throw new \RuntimeException('Unable to create the wallet concurrency barrier directory.');
        }

        $processes = [];
        $results = [];

        try {
            $database = config('database.connections.mysql');
            $environment = [
                'APP_ENV' => 'testing',
                'APP_KEY' => (string) config('app.key'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => (string) $database['host'],
                'DB_PORT' => (string) $database['port'],
                'DB_DATABASE' => 'wallet_management_test',
                'DB_USERNAME' => (string) $database['username'],
                'DB_PASSWORD' => (string) $database['password'],
                'DB_URL' => '',
                'DB_SOCKET' => '',
                'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
            ];

            for ($worker = 0; $worker < $processCount; $worker++) {
                $processes[] = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Support/parallel_wallet_operation.php'),
                        $operation,
                        (string) $walletId,
                        number_format($amount, 2, '.', ''),
                        $barrierDirectory,
                        (string) $worker,
                    ],
                    base_path(),
                    $environment,
                    null,
                    30,
                );
            }

            foreach ($processes as $process) {
                $process->start();
            }

            $deadline = microtime(true) + 20;
            while (count(glob($barrierDirectory.DIRECTORY_SEPARATOR.'ready-*') ?: []) < $processCount) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for concurrent wallet workers to reach the start barrier.');
                }

                usleep(10_000);
            }

            if (file_put_contents($barrierDirectory.DIRECTORY_SEPARATOR.'start', 'go') === false) {
                throw new \RuntimeException('Unable to release the concurrent wallet worker start barrier.');
            }

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    "Wallet worker failed.\nSTDOUT:\n{$process->getOutput()}\nSTDERR:\n{$process->getErrorOutput()}",
                );

                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertIsArray($result);
                $this->assertArrayHasKey('outcome', $result);
                $results[] = $result;
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach (glob($barrierDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($barrierDirectory);
        }

        return $results;
    }
}
