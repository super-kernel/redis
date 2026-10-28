<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RuntimeException;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Exception\RedisConnectionException;
use SuperKernel\Redis\Factory\RedisConnectionFactory;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use Throwable;

final class RedisConnectionTest extends TestCase
{
    /** @throws Throwable When database override setup or native connection initialization fails */
    public function testExplicitDatabaseOverrideDoesNotReplaceOrMutateApplicationConfiguration(): void
    {
        $source = RedisOptions::create(database: 3);
        $native = $this->native();
        $native->expects(self::once())->method('select')->with(1);
        $connection = new RedisConnection($source, $native, 1);

        $connection->connect();

        self::assertSame(3, $source->database);
        self::assertSame($native, $connection->client());
    }

    /** @throws Throwable When constructing the adapter fails */
    public function testNegativeAdapterDatabaseOverrideFailsBeforeNativeConnectionIo(): void
    {
        $native = $this->createMock(Redis::class);
        $native->expects(self::never())->method('connect');
        $this->expectException(InvalidArgumentException::class);

        new RedisConnection(RedisOptions::create(), $native, -1);
    }

    /** @throws Throwable When constructing the factory fails */
    public function testNegativeFactoryDatabaseOverrideFailsBeforeConnectionCreation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedisConnectionFactory(RedisOptions::create(), -1);
    }

    /** @throws Throwable When connection setup or access fails */
    public function testConstructionAndFactoryCreationDoNotOpenANetworkConnection(): void
    {
        $native = $this->createMock(Redis::class);
        $native->expects(self::never())->method('connect');
        $connection = new RedisConnection(RedisOptions::create(), $native);
        self::assertFalse($connection->isHealthy());
        $created = new RedisConnectionFactory(RedisOptions::create())->create();
        self::assertFalse($created->isHealthy());
        $this->expectException(RedisConnectionException::class);

        $connection->client();
    }

    /** @throws Throwable When connection setup or access fails */
    public function testConnectAppliesTimeoutsAclDatabaseAndDisablesAutomaticRetries(): void
    {
        $native = $this->native();
        $native->expects(self::once())->method('connect')->with('localhost', 6380, 0.2, null, 0, 0.3);
        $options = [];
        $native->expects(self::exactly(2))->method('setOption')->willReturnCallback(
            static function (int $option, mixed $value) use (&$options): bool {
                $options[$option] = $value;

                return true;
            },
        );
        $native->expects(self::once())->method('auth')->with([
            'user',
            'secret',
        ])->willReturn(true);
        $native->expects(self::once())->method('select')->with(3);
        $native->method('ping')->willReturn(true);
        $connection = new RedisConnection(RedisOptions::create(
            host: 'localhost',
            port: 6380,
            database: 3,
            username: 'user',
            password: 'secret',
            connectTimeout: 0.2,
            readTimeout: 0.3,
        ), $native);

        $connection->connect();
        $connection->connect();
        self::assertSame($native, $connection->client());
        self::assertTrue($connection->isHealthy());
        self::assertSame(0, $options[Redis::OPT_MAX_RETRIES]);
        self::assertSame(Redis::SERIALIZER_NONE, $options[Redis::OPT_SERIALIZER]);
    }

    /** @throws Throwable When connection setup fails */
    public function testPasswordOnlyAuthenticationUsesTheDefaultUser(): void
    {
        $native = $this->native();
        $native->expects(self::once())->method('auth')->with('secret')->willReturn(true);
        $connection = new RedisConnection(RedisOptions::create(password: 'secret'), $native);

        $connection->connect();
        self::assertSame($native, $connection->client());
    }

    /** @throws Throwable When connection setup or cleanup fails */
    public function testPartialInitializationCleanupPreservesTheAuthenticationFailure(): void
    {
        $native = $this->native();
        $cause = new RedisException('invalid authentication');
        $native->method('auth')->willThrowException($cause);
        $native->expects(self::once())->method('close')->willThrowException(new RuntimeException('cleanup failed'));
        $native->expects(self::never())->method('select');
        $connection = new RedisConnection(RedisOptions::create(password: 'invalid'), $native);

        try {
            $connection->connect();
            self::fail('Authentication failure must prevent the connection from becoming available.');
        } catch (RedisConnectionException $exception) {
            self::assertSame($cause, $exception->getPrevious());
        }

        self::assertFalse($connection->isHealthy());
        $this->expectException(RedisConnectionException::class);
        $connection->client();
    }

    /** @throws Throwable When connection setup or health inspection fails */
    public function testHealthCheckReturnsFalseOnANativePingFailure(): void
    {
        $native = $this->native();
        $native->expects(self::once())->method('ping')->willThrowException(new RedisException('connection lost'));
        $connection = new RedisConnection(RedisOptions::create(), $native);
        $connection->connect();

        self::assertFalse($connection->isHealthy());
    }

    /** @throws Throwable When reconnecting or accessing the native client fails */
    public function testExplicitReconnectRetainsTheAdapterAndReinitializesTheNativeClient(): void
    {
        $native = $this->native();
        $native->expects(self::exactly(2))->method('connect');
        $native->expects(self::once())->method('close')->willReturn(true);
        $connection = new RedisConnection(RedisOptions::create(), $native);
        $connection->connect();
        $connection->reconnect();

        self::assertSame($native, $connection->client());
    }

    /** @throws Throwable When connection setup or cleanup fails */
    public function testCloseClearsOwnershipAndRepeatedClosureDoesNotReopenTheSocket(): void
    {
        $native = $this->createConfiguredMock(Redis::class, [
            'connect'   => true,
            'setOption' => true,
            'select'    => true,
        ]);
        $connected = true;
        $native->method('isConnected')->willReturnCallback(static function () use (&$connected): bool {
            return $connected;
        });
        $native->expects(self::once())->method('close')->willReturnCallback(
            static function () use (&$connected): bool {
                $connected = false;

                return true;
            },
        );
        $connection = new RedisConnection(RedisOptions::create(), $native);
        $connection->connect();
        $connection->close();
        $connection->close();

        self::assertFalse($connection->isHealthy());
        $this->expectException(RedisConnectionException::class);
        $connection->client();
    }

    private function native(): Redis&MockObject
    {
        return $this->createConfiguredMock(Redis::class, [
            'connect'     => true,
            'isConnected' => true,
            'setOption'   => true,
            'select'      => true,
        ]);
    }
}
