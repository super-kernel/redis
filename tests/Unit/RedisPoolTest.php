<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use LogicException;
use PHPUnit\Framework\MockObject\MockObject;
use Redis;
use RedisException;
use ReflectionProperty;
use RuntimeException;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Provider\RedisFactoryProvider;
use SuperKernel\Redis\Proxy\Redis as ProxyRedis;
use SuperKernel\Redis\Tests\Fixture\ConfigurationRegistry;
use SuperKernel\Redis\Tests\Fixture\RecordingPool;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use SuperKernel\Redis\Tests\Support\CoroutineTestCase;
use Throwable;

use function spl_object_id;

final class RedisPoolTest extends CoroutineTestCase
{
    /** @throws Throwable When native dispatch, deferred cleanup, or fixture reflection fails */
    public function testInstanceAndStaticCallsShareTheDefaultCoroutineConnection(): void
    {
        $native = $this->native();
        $native->expects(self::exactly(2))->method('get')->with('key')->willReturn('value');
        [$factory, $pool, $owner] = $this->factory($native);

        // PHPUnit mock events require a bound TestCase on the coroutine call stack.
        $this->runCoroutine(function () use ($factory, $native): void {
            $proxy = new ProxyRedis();
            $this->assertSame('value', $proxy->get(key: 'key'));
            $this->assertSame('value', ProxyRedis::get(key: 'key'));
            $this->assertSame($native, $factory->get());
        });
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);
        self::assertSame([], $pool->released);
        $owner->close();
    }

    /** @throws Throwable When custom configuration validation fails */
    public function testCustomConfigurationIsValidatedBeforeOpeningAConnection(): void
    {
        $owner = new RedisPool();
        $config = RedisOptions::create(host: '');
        $this->expectException(InvalidRedisConfigurationException::class);

        $this->runCoroutine(static function () use ($owner, $config): void {
            $owner->get($config);
        });
    }

    /** @throws Throwable When an unowned native connection is discarded */
    public function testDiscardRejectsAnUnownedNativeClient(): void
    {
        $owner = new RedisPool();
        $this->expectException(PoolException::class);

        $this->runCoroutine(static function () use ($owner): void {
            $owner->discard(new Redis());
        });
    }

    /** @throws Throwable When native acquisition or retirement fails */
    public function testExplicitDiscardRetiresTheSessionOnceAndPreventsSameCoroutineReacquisition(): void
    {
        $native = $this->native();
        $native->expects(self::exactly(2))->method('isConnected')->willReturn(true);
        [$factory, $pool, $owner] = $this->factory($native);
        $this->runCoroutine(function () use ($factory, $owner, $native): void {
            $this->assertSame($native, $factory->get());
            $owner->discard($native);
            $owner->discard($native);
            try {
                $factory->get();
                self::fail('A retired session must not be replaced in the same coroutine.');
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        });
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);
        $owner->close();
    }

    /** @throws Throwable When pool shutdown or fixture reflection fails */
    public function testShutdownRetriesFailedCleanupWhileRemainingTerminalForAcquisition(): void
    {
        $owner = new RedisPool();
        $first = $this->createMock(PoolInterface::class);
        $second = $this->createMock(PoolInterface::class);
        $cause = new RuntimeException('first pool cleanup failed');
        $attempts = 0;
        $first->expects(self::exactly(2))->method('close')->willReturnCallback(
            static function () use (&$attempts, $cause): void {
                if (1 === ++$attempts) {
                    throw $cause;
                }
            },
        );
        $second->expects(self::exactly(2))->method('close');
        $this->pools($owner, [
            'first'  => $first,
            'second' => $second,
        ]);

        try {
            $owner->close();
            self::fail('The first cleanup failure must propagate after every pool is attempted.');
        } catch (RuntimeException $exception) {
            self::assertSame($cause, $exception);
        }
        $owner->close();
        $this->expectException(LogicException::class);

        $owner->get(RedisOptions::create());
    }

    /** @throws Throwable When native dispatch, deferred cleanup, or fixture reflection fails */
    public function testDeferredCleanupFailureIsCapturedAndSurfacesThroughTheNextAcquisitionOnce(): void
    {
        $native = $this->native();
        $native->expects(self::once())->method('get')->with('key')->willReturn('value');
        [$factory, $pool, $owner] = $this->factory($native);
        $proxy = ProxyRedis::class;
        $cause = new RuntimeException('deferred cleanup failed');
        $pool->discardFailure = $cause;

        // PHPUnit mock events require a bound TestCase on the coroutine call stack.
        $this->runCoroutine(function () use ($proxy): void {
            $this->assertSame('value', $proxy::get('key'));
        });
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);

        try {
            $factory->get();
            self::fail('Deferred cleanup failure must be surfaced to the acquisition caller.');
        } catch (RuntimeException $exception) {
            self::assertSame($cause, $exception);
        }
        $owner->close();
        $owner->close();

        self::assertTrue($pool->isClosed());
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);
    }

    /** @throws Throwable When native dispatch, deferred cleanup, or fixture reflection fails */
    public function testNativePrimaryFailureIsPreservedAndSecondaryCleanupSurfacesThroughShutdownOnce(): void
    {
        $native = $this->native();
        $primary = new RedisException('socket read failed');
        $native->expects(self::once())->method('get')->with('key')->willThrowException($primary);
        [, $pool, $owner] = $this->factory($native);
        $proxy = ProxyRedis::class;
        $secondary = new RuntimeException('retirement cleanup failed');
        $pool->discardFailure = $secondary;

        // PHPUnit mock events require a bound TestCase on the coroutine call stack.
        $this->runCoroutine(function () use ($proxy, $primary): void {
            try {
                $proxy::get('key');
                self::fail('The native primary failure must propagate.');
            } catch (RedisException $exception) {
                $this->assertSame($primary, $exception);
            }
        });

        try {
            $owner->close();
            self::fail('The secondary cleanup failure must be surfaced separately.');
        } catch (RuntimeException $exception) {
            self::assertSame($secondary, $exception);
        }
        $owner->close();

        self::assertTrue($pool->isClosed());
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);
        self::assertSame([], $pool->released);
    }

    /** @throws Throwable When native dispatch or fixture setup fails */
    public function testRebindingDuringAProxyCallRetiresTheConnectionThroughItsOriginalPool(): void
    {
        $native = $this->native();
        [, $pool, $owner] = $this->factory($native);
        $replacement = new RedisPool();
        $primary = new RedisException('socket read failed during rebinding');
        $native->expects(self::once())->method('get')->with('key')->willReturnCallback(
            static function () use ($replacement, $primary): never {
                new RedisFactoryProvider()($replacement, new RedisOptionLoader(new ConfigurationRegistry([
                    RedisConfig::class => RedisOptions::create(),
                ])));

                throw $primary;
            },
        );

        $this->runCoroutine(function () use ($pool, $primary): void {
            try {
                ProxyRedis::get('key');
                self::fail('The original native failure must propagate.');
            } catch (RedisException $exception) {
                $this->assertSame($primary, $exception);
            }
            self::assertSame([
                $pool->connection,
            ], $pool->discarded);
        });
        self::assertSame([
            $pool->connection,
        ], $pool->discarded);
        $owner->close();
        $replacement->close();
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

    /**
     * @return array{RedisFactory, RecordingPool, RedisPool}
     *
     * @throws Throwable When connection setup or fixture reflection fails
     */
    private function factory(Redis $native): array
    {
        $config = RedisOptions::create();
        $connection = new RedisConnection($config, $native);
        $connection->connect();
        $pool = new RecordingPool($connection);
        $owner = new RedisPool();
        $factory = new RedisFactoryProvider()($owner, new RedisOptionLoader(new ConfigurationRegistry([
            RedisConfig::class => $config,
        ])));
        $this->pools($owner, [
            'option:' . spl_object_id($config->poolOption) => $pool,
        ]);

        return [
            $factory,
            $pool,
            $owner,
        ];
    }

    /**
     * @param array<string, PoolInterface<RedisConnection>> $pools
     */
    private function pools(RedisPool $owner, array $pools): void
    {
        new ReflectionProperty(RedisPool::class, 'pools')->setValue($owner, $pools);
    }
}
