<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Redis;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Provider\RedisFactoryProvider;
use SuperKernel\Redis\Proxy\Redis as ProxyRedis;
use SuperKernel\Redis\Tests\Fixture\ConfigurationRegistry;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use Throwable;

use function array_map;
use function sort;

final class RedisFactoryTest extends TestCase
{
    /**
     * Static proxy calls reach __callStatic(); the IDE resolves the mixed-in native Redis methods as non-static.
     *
     * @throws Throwable When an unbound proxy fails
     *
     * @noinspection PhpDynamicAsStaticMethodCallInspection
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticCallsRequireProviderInitialization(): void
    {
        $this->expectException(LogicException::class);

        ProxyRedis::get('unbound');
    }

    /** @throws ReflectionException When the factory parameter cannot be reflected */
    public function testFactoryOnlyAcquiresConnectionsAndProxiesOnlyDeclareMagicDispatch(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(RedisFactory::class)->getMethods(),
        );
        sort($methods);
        self::assertSame([
            '__construct',
            'get',
        ], $methods);
        self::assertSame('Redis', (string) new ReflectionMethod(RedisFactory::class, 'get')->getReturnType());
        $name = new ReflectionMethod(RedisFactory::class, 'get')->getParameters()[0];
        self::assertSame('?string', (string) $name->getType());
        self::assertNull($name->getDefaultValue());

        $proxy = new ReflectionClass(ProxyRedis::class);
        $methods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $proxy->getMethods(),
        );
        sort($methods);
        self::assertSame([
            '__call',
            '__callStatic',
        ], $methods);
        self::assertTrue($proxy->isFinal());
        self::assertFalse($proxy->isSubclassOf(Redis::class));
    }

    /** @throws Throwable When pool shutdown fails */
    public function testProviderInitializesTheProxyWithoutOpeningANativeConnection(): void
    {
        $pool = new RedisPool();
        new RedisFactoryProvider()($pool, new RedisOptionLoader(new ConfigurationRegistry([
            RedisConfig::class => RedisOptions::create(host: 'unreachable-redis.invalid'),
        ])));
        $proxy = new ProxyRedis();
        $pool->close();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('The Redis pool is closed.');

        $proxy->ping();
    }
}
