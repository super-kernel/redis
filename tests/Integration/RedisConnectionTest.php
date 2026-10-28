<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Integration;

use Redis;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use SuperKernel\Redis\Tests\Support\CoroutineTestCase;
use Throwable;

use function ctype_digit;
use function getenv;

final class RedisConnectionTest extends CoroutineTestCase
{
    /** @throws Throwable When coroutine execution or Redis connection management fails */
    public function testLiveConnectionHealthExplicitReconnectAndRepeatedClose(): void
    {
        $native = new Redis();
        $connection = new RedisConnection($this->configuration(), $native);

        $this->runCoroutine(static function () use ($connection, $native): void {
            self::assertFalse($connection->isHealthy());
            try {
                $connection->connect();
                self::assertSame($native, $connection->client());
                self::assertTrue($connection->isHealthy());
                $connection->reconnect();
                self::assertSame($native, $connection->client());
                self::assertTrue($connection->isHealthy());
            } finally {
                $connection->close();
            }

            self::assertFalse($connection->isHealthy());
            self::assertFalse($native->isConnected());
            $connection->close();
        });
    }

    /** @throws Throwable When assembling the live connection option fails */
    private function configuration(): RedisConfig
    {
        $port = getenv('REDIS_TEST_PORT');
        if (false === $port || !ctype_digit($port) || 1 > (int) $port || 65535 < (int) $port) {
            self::markTestSkipped('Set REDIS_TEST_PORT to a disposable local Redis server TCP port.');
        }

        return RedisOptions::create(port: (int) $port, maxConnections: 1);
    }
}
