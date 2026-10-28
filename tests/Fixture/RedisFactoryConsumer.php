<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Proxy\Redis;

final readonly class RedisFactoryConsumer
{
    public function __construct(public RedisFactory $factory, public Redis $proxy, public RedisPool $pool)
    {
    }
}
