<?php

namespace Tests\Integration;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\TestCase;

abstract class MySqlIntegrationTestCase extends TestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || $database !== 'wallet_management_test') {
            throw new RuntimeException(
                'Integration tests require the dedicated MySQL database "wallet_management_test". '
                .'Configure it in .env.testing; do not use an application or production database.'
            );
        }

        return $app;
    }

    protected function createWallet(string $balance): Wallet
    {
        return Wallet::create([
            'user_id' => User::factory()->create()->id,
            'balance' => $balance,
        ]);
    }
}
