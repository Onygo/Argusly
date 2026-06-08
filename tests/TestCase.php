<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $this->primeTestingEnvironment();

        /** @var Application $app */
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->applyTestingRuntimeConfig($app);
        $this->assertTestingIsolation($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTestingIsolation($this->app);

        // Keep feature tests deterministic regardless of local soft-launch settings.
        config([
            'argusly.launch.soft_launch_mode' => false,
            'argusly.launch.public_registration_enabled' => true,
            'argusly.launch.public_pricing_enabled' => true,
            'argusly.launch.registration_block_mode' => 'redirect',
        ]);
    }

    private function primeTestingEnvironment(): void
    {
        foreach ([
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:8+M8xq3M9M7JQm0t2F0P6N4sQ6m3xY0uA4zKj5pL1wQ=',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ] as $key => $value) {
            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function applyTestingRuntimeConfig(Application $app): void
    {
        $config = $app['config'];

        $config->set('app.env', 'testing');
        $config->set('app.key', 'base64:8+M8xq3M9M7JQm0t2F0P6N4sQ6m3xY0uA4zKj5pL1wQ=');
        $config->set('cache.default', 'array');
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.database', ':memory:');
        $config->set('mail.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('session.driver', 'array');
    }

    private function assertTestingIsolation(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('Tests must run with APP_ENV=testing.');
        }

        $defaultConnection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get(sprintf('database.connections.%s.database', $defaultConnection));
        $queueConnection = (string) $app['config']->get('queue.default');

        if ($defaultConnection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against [%s] database [%s]. Tests must use sqlite :memory: only.',
                $defaultConnection,
                $database !== '' ? $database : 'unknown'
            ));
        }

        if ($queueConnection !== 'sync') {
            throw new RuntimeException(sprintf(
                'Refusing to run tests with queue connection [%s]. Tests must use sync queues.',
                $queueConnection !== '' ? $queueConnection : 'unknown'
            ));
        }
    }
}
