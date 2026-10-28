<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Pool;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Factory\RedisConnectionFactory;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use Throwable;

final class PoolAssemblyTest extends TestCase
{
    /** @throws Throwable When assembling, binding or creating an unconnected resource fails */
    public function testPoolFactoryCanBindTheCompletedOptionExactlyOnceWithoutConnecting(): void
    {
        $option = RedisOptions::create(host: 'unreachable-redis.invalid');
        $factory = $option->poolOption->factory();
        self::assertInstanceOf(RedisConnectionFactory::class, $factory);
        $factory->bind($option);
        $factory->bind($option);
        $connection = $factory->create();

        self::assertSame($option, new ReflectionProperty(RedisConnection::class, 'option')->getValue($connection));
        self::assertFalse($connection->isHealthy());
        $this->expectException(InvalidArgumentException::class);

        $factory->bind(RedisOptions::create());
    }

    public function testAnUnboundFactoryCannotCreateAConnection(): void
    {
        $this->expectException(LogicException::class);

        new RedisConnectionFactory()->create();
    }

    /** @throws Throwable When option assembly, connection creation or unused shutdown fails */
    public function testConcretePoolOptionsPreserveFactoryConfigurationWithoutConnecting(): void
    {
        $config = RedisOptions::create(host: 'unreachable-redis.invalid', maxConnections: 3, waitTimeout: 0.25);
        $option = new PoolOption(
            factory: new RedisConnectionFactory($config, 7),
            capacity: new CoroutineCapacity($config->poolOption->maxConnections()),
            maxConnections: $config->poolOption->maxConnections(),
            waitTimeout: $config->poolOption->waitTimeout(),
        );
        $pool = new Pool($option);
        $connection = $option->factory()->create();

        self::assertSame($config, new ReflectionProperty(RedisConnection::class, 'option')->getValue($connection));
        self::assertSame(7, new ReflectionProperty(RedisConnection::class, 'database')->getValue($connection));
        self::assertSame(0, $config->database);
        self::assertSame(3, $option->maxConnections());
        self::assertSame(0.25, $option->waitTimeout());
        self::assertSame(3, $option->capacity()->capacity());
        self::assertFalse($connection->isHealthy());
        self::assertFalse($pool->isClosed());
        $pool->close();
        $connection->close();
    }

    /** @throws Throwable When independent option assembly or unused shutdown fails */
    public function testDatabaseOptionsKeepTheirCapacityAndShutdownIndependent(): void
    {
        $config = RedisOptions::create(host: 'unreachable-redis.invalid');
        $first = new Pool(new PoolOption(
            new RedisConnectionFactory($config, 1),
            new CoroutineCapacity($config->poolOption->maxConnections()),
            $config->poolOption->maxConnections(),
            $config->poolOption->waitTimeout(),
        ));
        $second = new Pool(new PoolOption(
            new RedisConnectionFactory($config, 2),
            new CoroutineCapacity($config->poolOption->maxConnections()),
            $config->poolOption->maxConnections(),
            $config->poolOption->waitTimeout(),
        ));

        $first->close();

        self::assertTrue($first->isClosed());
        self::assertFalse($second->isClosed());
        $second->close();
    }
}
