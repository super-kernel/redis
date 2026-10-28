<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Proxy;

use LogicException;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use Throwable;

/** @mixin \Redis */
final class Redis
{
    /**
     * RedisFactoryProvider writes this property through a closure bound to this class.
     *
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    private static RedisFactory $factory;

    /**
     * RedisFactoryProvider writes this property through a closure bound to this class.
     *
     * @noinspection PhpPropertyOnlyWrittenInspection
     */
    private static RedisPool $pool;

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return mixed The unchanged native result dispatched through the static proxy
     *
     * @throws Throwable When acquiring or invoking the native connection fails
     */
    public function __call(string $name, array $arguments): mixed
    {
        return self::__callStatic($name, $arguments);
    }

    /**
     * @param array<array-key, mixed> $arguments
     *
     * @return mixed The unchanged native result for the current coroutine
     *
     * @throws LogicException When RedisFactoryProvider has not initialized the proxy
     * @throws Throwable      When acquisition, native dispatch, or deferred cleanup fails
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (!isset(self::$factory, self::$pool)) {
            throw new LogicException('The Redis proxy requires initialization through RedisFactoryProvider.');
        }

        $pool = self::$pool;
        $redis = self::$factory->get();
        try {
            return $redis->{$name}(...$arguments);
        } catch (Throwable $exception) {
            $pool->discard($redis, $exception);

            throw $exception;
        }
    }
}
