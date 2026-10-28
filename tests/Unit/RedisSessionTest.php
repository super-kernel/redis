<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Session\RedisSession;
use SuperKernel\Redis\Tests\Fixture\RecordingPool;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use Throwable;

final class RedisSessionTest extends TestCase
{
    /** @throws Throwable When session cleanup fails */
    public function testNativeIdentityIsPreservedUntilExactlyOneRetirement(): void
    {
        $connection = $this->connection();
        $pool = new RecordingPool($connection);
        $session = new RedisSession($connection, $pool);

        self::assertSame($connection->client(), $session->client());
        self::assertSame($connection->client(), $session->client());
        self::assertSame([], $pool->discarded);
        $session->close();
        $session->close();

        self::assertSame([
            $connection,
        ], $pool->discarded);
        self::assertSame([], $pool->released);
        $this->expectException(LogicException::class);

        $session->client();
    }

    /** @throws Throwable When session cleanup fails */
    public function testCleanupFailureIsPropagatedWithoutRepeatingRetirement(): void
    {
        $connection = $this->connection();
        $pool = new RecordingPool($connection);
        $cause = new RuntimeException('cleanup failed');
        $pool->discardFailure = $cause;
        $session = new RedisSession($connection, $pool);

        try {
            $session->close();
            self::fail('Cleanup failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($cause, $exception);
        }
        $session->close();

        self::assertSame([
            $connection,
        ], $pool->discarded);
        $this->expectException(LogicException::class);

        $session->client();
    }
    /** @throws Throwable When preparing the connected fixture fails */
    private function connection(): RedisConnection
    {
        $native = self::createConfiguredStub(Redis::class, [
            'connect'     => true,
            'isConnected' => true,
            'setOption'   => true,
            'select'      => true,
        ]);
        $connection = new RedisConnection(RedisOptions::create(), $native);
        $connection->connect();

        return $connection;
    }
}
