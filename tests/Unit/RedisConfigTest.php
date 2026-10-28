<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use Redis;
use ReflectionClass;
use ReflectionProperty;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Pool\Exception\PoolTimeoutException;
use SuperKernel\Pool\Frequency\SmartLowFrequency;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;
use SuperKernel\Redis\Exception\RedisConnectionException;
use SuperKernel\Redis\Factory\RedisConnectionFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Tests\Support\CoroutineTestCase;
use Throwable;

use function array_map;
use function count;

final class RedisConfigTest extends CoroutineTestCase
{
    /** @throws Throwable When explicit pool assembly, borrowing or cleanup fails */
    public function testExplicitOptionsPreserveIdentityAndShareCapacityAcrossDatabaseSessions(): void
    {
        $native = $this->native();
        $selected = [];
        $native->method('select')->willReturnCallback(static function (int $database) use (&$selected): bool {
            $selected[] = $database;

            return true;
        });
        $native->expects(self::once())->method('close')->willReturn(true);
        $option = $this->option(3, $native);
        $owner = new RedisPool();

        $this->runCoroutine(function () use ($owner, $option, $native): void {
            try {
                $this->assertSame($native, $owner->get($option, 7));
                $this->assertSame($native, $owner->get($option, 7));
                try {
                    $owner->get($option, 8);
                    self::fail('Database overrides must share the supplied pool capacity.');
                } catch (PoolTimeoutException) {
                    $this->assertSame(0, $option->poolOption->capacity()->available());
                    $this->assertSame(2.0, $option->poolOption->frequency()->frequency());
                }
            } finally {
                $owner->close();
            }
        });
        self::assertSame([
            3,
            7,
        ], $selected);
        self::assertSame(3, $option->database);
        self::assertSame(1, $option->poolOption->maxConnections());
    }

    /** @throws Throwable When independent explicit pools are used or closed */
    public function testDistinctPoolOptionsDoNotReuseAnotherOptionsSession(): void
    {
        $first = $this->native();
        $second = $this->native();
        $first->method('select')->willReturn(true);
        $second->method('select')->willReturn(true);
        $first->expects(self::once())->method('close')->willReturn(true);
        $second->expects(self::once())->method('close')->willReturn(true);
        $one = $this->option(0, $first);
        $two = $this->option(0, $second);
        $owner = new RedisPool();

        $this->runCoroutine(function () use ($owner, $one, $two, $first, $second): void {
            try {
                $this->assertSame($first, $owner->get($one));
                $this->assertSame($second, $owner->get($two));
            } finally {
                $owner->close();
            }
        });
    }

    /** @throws Throwable When configuration identity validation or cleanup fails */
    public function testOnePoolOptionCannotBeReboundToAnotherRedisConfiguration(): void
    {
        $native = $this->native();
        $native->method('select')->willReturn(true);
        $native->expects(self::once())->method('close')->willReturn(true);
        $option = $this->option(0, $native);
        $conflict = new RedisConfig($option->poolOption, host: 'another-redis.invalid');
        $owner = new RedisPool();

        $this->runCoroutine(function () use ($owner, $option, $conflict): void {
            try {
                $owner->get($option);
                $this->expectException(InvalidArgumentException::class);
                $owner->get($conflict);
            } finally {
                $owner->close();
            }
        });
    }

    /** @throws Throwable When database selection or resource cleanup fails */
    public function testFailedDatabaseSelectionDiscardsTheConnectionBeforeAnotherAcquisition(): void
    {
        $broken = $this->native();
        $broken->method('select')->willReturnOnConsecutiveCalls(true, false);
        $broken->expects(self::once())->method('close')->willReturn(true);
        $healthy = $this->native();
        $healthy->method('select')->willReturn(true);
        $healthy->expects(self::once())->method('close')->willReturn(true);
        $option = $this->option(0, $broken, $healthy);
        $owner = new RedisPool();

        $this->runCoroutine(function () use ($owner, $option, $healthy): void {
            try {
                try {
                    $owner->get($option, 7);
                    self::fail('Failed database selection must not expose a session.');
                } catch (RedisConnectionException $exception) {
                    $this->assertSame('Selecting the Redis database failed.', $exception->getMessage());
                    $this->assertSame(2, $option->poolOption->capacity()->available());
                }
                $this->assertSame($healthy, $owner->get($option, 7));
            } finally {
                $owner->close();
            }
        });
    }

    /** @throws Throwable When invalid base configuration is rejected */
    public function testExplicitPoolOptionsDoNotBypassRedisConfigurationValidation(): void
    {
        $factory = $this->createMock(ConnectionFactoryInterface::class);
        $factory->expects(self::never())->method('create');
        $poolOption = new PoolOption($factory, new CoroutineCapacity(1), 1);
        $option = new RedisConfig($poolOption, host: '');
        $owner = new RedisPool();
        $this->expectException(InvalidRedisConfigurationException::class);

        $this->runCoroutine(static function () use ($owner, $option): void {
            $owner->get($option);
        });
    }

    /** @throws Throwable When shutdown interrupts database selection or cleanup fails */
    public function testShutdownDuringDatabaseSelectionDoesNotExposeTheBorrowedConnection(): void
    {
        $owner = new RedisPool();
        $native = $this->native();
        $selections = 0;
        $native->method('select')->willReturnCallback(static function () use ($owner, &$selections): bool {
            if (2 === ++$selections) {
                $owner->close();
            }

            return true;
        });
        $native->expects(self::once())->method('close')->willReturn(true);
        $option = $this->option(0, $native);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The Redis pool is closed.');

        $this->runCoroutine(function () use ($owner, $option): void {
            $owner->get($option, 7);
        });
    }

    /** @return Redis&MockObject */
    private function native(): Redis
    {
        return $this->createConfiguredMock(Redis::class, [
            'connect'     => true,
            'isConnected' => true,
            'setOption'   => true,
        ]);
    }

    /** @throws Throwable When creating the option or reflecting its public contract fails */
    public function testOptionsExposeOnlyPublicReadonlyProperties(): void
    {
        $option = new RedisConfig(new PoolOption(
            new RedisConnectionFactory(),
            new CoroutineCapacity(1),
            1,
        ));
        $class = new ReflectionClass($option);
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isPublic());
            self::assertTrue($property->isReadOnly());
        }
        $this->expectException(Error::class);

        new ReflectionProperty($option, 'host')->setValue($option, 'changed');
    }

    /** @throws Throwable When creating the capacity coordinator or frequency policy fails */
    private function option(int $database, Redis ...$clients): RedisConfig
    {
        $factory = $this->createMock(ConnectionFactoryInterface::class);
        $option = new RedisConfig(
            poolOption: new PoolOption(
                factory: $factory,
                capacity: new CoroutineCapacity(count($clients)),
                maxConnections: count($clients),
                waitTimeout: 0.0,
                healthChecks: HealthCheckMode::Manual,
                frequency: new SmartLowFrequency(windowSeconds: 1.0, clock: static fn(): float => 0.0),
            ),
            database: $database,
        );
        $connections = array_map(
            static fn(Redis $client): RedisConnection => new RedisConnection($option, $client),
            $clients,
        );
        $factory->expects(
            self::exactly(count($clients)),
        )->method('create')->willReturnOnConsecutiveCalls(...$connections);

        return $option;
    }
}
