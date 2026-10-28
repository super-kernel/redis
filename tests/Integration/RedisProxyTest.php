<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Integration;

use Error;
use LogicException;
use Redis;
use RedisException;
use SuperKernel\Attribute\Configuration;
use SuperKernel\Config\Config;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\ContainerInterface;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Provider\RedisFactoryProvider;
use SuperKernel\Redis\Proxy\Redis as ProxyRedis;
use SuperKernel\Redis\Tests\Fixture\ConfigurationRegistry;
use SuperKernel\Redis\Tests\Fixture\RedisConfigurationItems;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use SuperKernel\Redis\Tests\Support\CoroutineTestCase;
use SuperKernel\Reflector\Provider\ReflectorProvider;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

use function array_key_last;
use function array_merge;
use function bin2hex;
use function ctype_digit;
use function getenv;
use function random_bytes;
use function sort;

final class RedisProxyTest extends CoroutineTestCase
{
    /** @throws Throwable When named Redis acquisition or shutdown fails */
    public function testMultipleItemsWithoutADefaultStillProvideNamedRedisConnections(): void
    {
        $source = new class() implements MultipleConfigInterface {
            #[Configuration]
            public function primary(): RedisConfig
            {
                return RedisOptions::create(port: (int) getenv('REDIS_TEST_PORT'));
            }

            #[Configuration]
            public function cache(): RedisConfig
            {
                return RedisOptions::create(port: (int) getenv('REDIS_TEST_PORT'), database: 1);
            }
        };
        [$factory, $pool] = $this->factory(source: $source);
        $this->runCoroutine(static function () use ($factory, $pool): void {
            try {
                try {
                    $factory->get();
                    self::fail('An unnamed acquisition must fail when several items have no default.');
                } catch (ConfigurationNotFoundException $exception) {
                    self::assertStringContainsString('no default item', $exception->getMessage());
                }
                $cache = $factory->get('cache');
                self::assertTrue($cache->ping());
                self::assertSame($cache, $factory->get('cache'));
                self::assertNotSame($cache, $factory->get('primary'));
            } finally {
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native acquisition, commands, or pool cleanup fail */
    public function testFactoryReturnsNativeConnectionsForNamedItemsAndDatabaseOverrides(): void
    {
        $source = new RedisConfigurationItems();
        $source->port = (int) getenv('REDIS_TEST_PORT');
        $source->maxConnections = 2;
        [$factory, $pool] = $this->factory(source: $source);
        $key = 'super-kernel:redis-factory-config-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($factory, $pool, $key): void {
            $default = $factory->get();
            $custom = $factory->get('cache');
            $override = $factory->get('cache', database: 2);
            $independent = $factory->get('independent');
            try {
                self::assertSame($default, $factory->get());
                self::assertSame($custom, $factory->get('cache'));
                self::assertNotSame($default, $custom);
                self::assertNotSame($custom, $override);
                self::assertNotSame($default, $independent);
                foreach ([
                    null,
                    'primary',
                ] as $name) {
                    self::assertSame($default, $factory->get($name));
                }
                self::assertTrue($default->set($key, 'default'));
                self::assertTrue($custom->set($key, 'custom'));
                self::assertTrue($override->set($key, 'override'));
                self::assertSame('default', $default->get($key));
                self::assertSame('custom', $custom->get($key));
                self::assertSame('default', ProxyRedis::get($key));
                self::assertSame($default->rawCommand('CLIENT', 'ID'), ProxyRedis::rawCommand('CLIENT', 'ID'));
                self::assertSame('override', $override->get($key));
                self::assertSame('default', $independent->get($key));
                self::assertNotSame($default->rawCommand('CLIENT', 'ID'), $independent->rawCommand('CLIENT', 'ID'));
            } finally {
                $default->del($key);
                $custom->del($key);
                $override->del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When instance or static dispatch fails */
    public function testInstanceCallsForwardToTheStaticProxyAndShareItsNativeConnection(): void
    {
        [$factory, $pool] = $this->factory();
        $proxy = new ProxyRedis();
        $key = 'super-kernel:redis-instance-proxy-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($factory, $pool, $proxy, $key): void {
            try {
                self::assertTrue($proxy->set(key: $key, value: 'instance'));
                self::assertSame('instance', ProxyRedis::get($key));
                self::assertSame($factory->get()->rawCommand('CLIENT', 'ID'), $proxy->rawCommand('CLIENT', 'ID'));
                self::assertSame($factory->get(), $proxy->multi());
                self::assertSame($factory->get(), ProxyRedis::set($key, 'static'));
                self::assertSame([
                    true,
                ], $proxy->exec());
                self::assertSame('static', $proxy->get($key));
            } finally {
                $proxy->del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When a native Redis call or factory cleanup fails */
    public function testStaticProxyUsesTheProviderBindingWithoutConstruction(): void
    {
        [, $pool] = $this->factory();
        $key = 'super-kernel:redis-proxy-static-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($pool, $key): void {
            try {
                self::assertTrue(ProxyRedis::set($key, 'fallback'));
                self::assertSame('fallback', ProxyRedis::get($key));
            } finally {
                ProxyRedis::del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When a native Redis call or factory cleanup fails */
    public function testPoolShutdownRejectsFutureCallsAndAllowsDeferredRetirementToFinish(): void
    {
        [, $pool] = $this->factory();

        $this->runCoroutine(static function () use ($pool): void {
            self::assertTrue(ProxyRedis::ping());
            $pool->close();
            $pool->close();

            try {
                ProxyRedis::ping();
                self::fail('A closed Redis pool must reject future native dispatch.');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        });
    }

    /** @throws Throwable When native cursor dispatch or coroutine cleanup fails */
    public function testFactoryNativeClientPreservesAllScanCursorReferences(): void
    {
        [$factory, $pool] = $this->factory();
        $prefix = 'super-kernel:redis-proxy-scan-test:' . bin2hex(random_bytes(8));
        $hashKey = $prefix . ':hash';
        $setKey = $prefix . ':set';
        $sortedKey = $prefix . ':sorted';

        $this->runCoroutine(static function () use ($factory, $pool, $prefix, $hashKey, $setKey, $sortedKey): void {
            $native = $factory->get();
            $keys = [];
            for ($index = 0; 20 > $index; ++$index) {
                $keys[] = $prefix . ':' . $index;
            }

            try {
                foreach ($keys as $key) {
                    ProxyRedis::set($key, 'scan-value');
                }
                $cursor = null;
                $found = [];
                do {
                    $batch = $native->scan($cursor, $prefix . ':*', 1);
                    if (false !== $batch) {
                        $found = array_merge($found, $batch);
                    }
                    self::assertNotNull($cursor);
                } while (0 !== $cursor);
                sort($keys);
                sort($found);
                self::assertSame($keys, $found);

                ProxyRedis::hSet($hashKey, 'field', 'value');
                $hashCursor = null;
                self::assertSame([
                    'field' => 'value',
                ], $native->hScan($hashKey, $hashCursor));
                self::assertSame(0, $hashCursor);

                ProxyRedis::sAdd($setKey, 'member');
                $setCursor = null;
                self::assertSame([
                    'member',
                ], $native->sScan($setKey, $setCursor));
                self::assertSame(0, $setCursor);

                ProxyRedis::zAdd($sortedKey, 1.5, 'member');
                $sortedCursor = null;
                self::assertSame([
                    'member' => 1.5,
                ], $native->zScan($sortedKey, $sortedCursor));
                self::assertSame(0, $sortedCursor);
            } finally {
                ProxyRedis::del($hashKey, $setKey, $sortedKey, ...$keys);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native Redis dispatch or coroutine cleanup fails */
    public function testNamedArgumentsNativeFluentRepliesAndOneSessionPerCoroutine(): void
    {
        [, $pool] = $this->factory();
        $key = 'super-kernel:redis-proxy-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($pool, $key): void {
            try {
                $first = ProxyRedis::rawCommand('CLIENT', 'ID');
                self::assertIsInt($first);
                self::assertTrue(ProxyRedis::set(key: $key, value: 'named', options: [
                    'ex' => 60,
                ]));
                self::assertSame('named', ProxyRedis::get($key));
                self::assertSame($first, ProxyRedis::rawCommand('CLIENT', 'ID'));
                $native = ProxyRedis::multi();
                self::assertInstanceOf(Redis::class, $native);
                self::assertSame($native, ProxyRedis::set($key, 'transaction'));
                self::assertSame([
                    true,
                ], ProxyRedis::exec());
                self::assertSame('transaction', ProxyRedis::get($key));
            } finally {
                ProxyRedis::del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native Redis dispatch or coroutine cleanup fails */
    public function testFactoryDatabaseOverrideKeepsTheProxyDefaultIndependent(): void
    {
        [$factory, $pool] = $this->factory(2);
        $key = 'super-kernel:redis-proxy-database-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($factory, $pool, $key): void {
            $one = $factory->get(database: 1);
            try {
                self::assertTrue(ProxyRedis::set($key, 'default-database'));
                self::assertTrue($one->set($key, 'database-one'));
                self::assertSame('default-database', ProxyRedis::get($key));
                self::assertSame('database-one', $one->get($key));
                self::assertNotSame(ProxyRedis::rawCommand('CLIENT', 'ID'), $one->rawCommand('CLIENT', 'ID'));
            } finally {
                ProxyRedis::del($key);
                $one->del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native Redis dispatch or coroutine cleanup fails */
    public function testCoroutineEndRetiresTheSessionAndRestoresSingleConnectionCapacity(): void
    {
        [, $pool] = $this->factory();
        $ids = [];
        for ($iteration = 0; 2 > $iteration; ++$iteration) {
            $this->runCoroutine(static function () use (&$ids): void {
                $ids[] = ProxyRedis::rawCommand('CLIENT', 'ID');
                self::assertSame($ids[array_key_last($ids)], ProxyRedis::rawCommand('CLIENT', 'ID'));
            });
        }
        $this->runCoroutine(static function () use ($pool): void {
            $pool->close();
        });

        self::assertCount(2, $ids);
        self::assertNotSame($ids[0], $ids[1]);
    }

    /** @throws Throwable When native Redis dispatch or either coroutine fails */
    public function testSimultaneousCoroutinesHaveDifferentNativeSocketsAndTransactionState(): void
    {
        [, $pool] = $this->factory(2);
        $key = 'super-kernel:redis-proxy-concurrent-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($pool, $key): void {
            $ready = new Channel(1);
            $resume = new Channel(1);
            $finished = new Channel(1);
            $failure = null;
            $id = Coroutine::create(static function () use ($key, $ready, $resume, $finished, &$failure): void {
                try {
                    $socket = ProxyRedis::rawCommand('CLIENT', 'ID');
                    ProxyRedis::multi();
                    ProxyRedis::set($key, 'transaction');
                    $ready->push($socket);
                    self::assertTrue($resume->pop(1.0));
                    self::assertSame([
                        true,
                    ], ProxyRedis::exec());
                } catch (Throwable $exception) {
                    $failure = $exception;
                } finally {
                    $finished->push(true);
                }
            });
            self::assertIsInt($id);

            try {
                try {
                    $first = $ready->pop(1.0);
                    self::assertIsInt($first);
                    self::assertNotSame($first, ProxyRedis::rawCommand('CLIENT', 'ID'));
                    self::assertFalse(ProxyRedis::get($key));
                    self::assertTrue(ProxyRedis::set($key, 'outside'));
                    self::assertSame('outside', ProxyRedis::get($key));
                } finally {
                    $resume->push(true);
                    self::assertTrue($finished->pop(1.0));
                }

                if (null !== $failure) {
                    throw $failure;
                }

                self::assertSame('transaction', ProxyRedis::get($key));
            } finally {
                ProxyRedis::del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native Redis dispatch or coroutine cleanup fails */
    public function testNativeErrorsRemainUnwrappedAndRetireTheCurrentCoroutineSession(): void
    {
        [, $pool] = $this->factory();
        $key = 'super-kernel:redis-proxy-error-test:' . bin2hex(random_bytes(8));

        $this->runCoroutine(static function () use ($key): void {
            $socket = ProxyRedis::rawCommand('CLIENT', 'ID');
            ProxyRedis::set($key, 'non-numeric');
            self::assertFalse(ProxyRedis::incr($key));
            self::assertSame($socket, ProxyRedis::rawCommand('CLIENT', 'ID'));
            self::assertSame('non-numeric', ProxyRedis::get($key));
            $native = ProxyRedis::multi();
            self::assertInstanceOf(Redis::class, $native);
            self::assertTrue($native->discard());
            self::assertSame(1, $native->rawCommand('CLIENT', 'KILL', 'ID', $socket, 'SKIPME', 'no'));
            try {
                ProxyRedis::get($key);
                self::fail('A socket closed by the server must propagate the original phpredis failure.');
            } catch (RedisException $exception) {
                self::assertSame(RedisException::class, $exception::class);
                self::assertNull($exception->getPrevious());
            }

            try {
                ProxyRedis::get($key);
                self::fail('A failed native session must not reconnect within the same coroutine.');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        });

        $this->runCoroutine(static function () use ($pool, $key): void {
            try {
                self::assertSame('non-numeric', ProxyRedis::get($key));
            } finally {
                ProxyRedis::del($key);
                $pool->close();
            }
        });
    }

    /** @throws Throwable When native Redis dispatch or coroutine cleanup fails */
    public function testAnUnknownNativeMethodPreservesTheOriginalPhpError(): void
    {
        [, $pool] = $this->factory();

        $this->runCoroutine(static function () use ($pool): void {
            try {
                ProxyRedis::__callStatic('missingRedisNativeMethod', []);
                self::fail('A nonexistent native Redis method must fail.');
            } catch (Error $exception) {
                self::assertStringContainsString('Redis::missingRedisNativeMethod', $exception->getMessage());
            } finally {
                $pool->close();
            }
        });
    }

    /**
     * @return array{RedisFactory, RedisPool}
     *
     * @throws Throwable When proxy initialization fails
     */
    private function factory(int $maxConnections = 1, ?MultipleConfigInterface $source = null): array
    {
        $port = getenv('REDIS_TEST_PORT');
        if (false === $port || !ctype_digit($port) || 1 > (int) $port || 65535 < (int) $port) {
            self::markTestSkipped('Set REDIS_TEST_PORT to a disposable local Redis server TCP port.');
        }

        $pool = new RedisPool();
        if (null !== $source) {
            $container = $this->createStub(ContainerInterface::class);
            $container->method('get')->willReturn($source);
            $config = new Config(
                [
                    RedisConfig::class => $source::class,
                ],
                $container,
                new ReflectorProvider()(),
            );
        } else {
            $config = new ConfigurationRegistry([
                RedisConfig::class => RedisOptions::create(port: (int) $port, maxConnections: $maxConnections),
            ]);
        }

        return [
            new RedisFactoryProvider()($pool, new RedisOptionLoader($config)),
            $pool,
        ];
    }
}
