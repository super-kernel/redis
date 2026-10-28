<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Provider;

use Closure;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Proxy\Redis;

#[FactoryProvider(RedisFactory::class)]
final readonly class RedisFactoryProvider
{
    public function __invoke(RedisPool $pool, RedisOptionLoader $config): RedisFactory
    {
        $factory = new RedisFactory($pool, $config);
        Closure::bind(
            static function (RedisFactory $factory, RedisPool $pool): void {
                Redis::$factory = $factory;
                Redis::$pool = $pool;
            },
            null,
            Redis::class,
        )($factory, $pool);

        return $factory;
    }
}
