<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Factory;

use Redis;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Pool\RedisPool;
use Throwable;

/** Retrieves native connections from the Redis pool using stable application configuration. */
final readonly class RedisFactory
{
    public function __construct(private RedisPool $pool, private RedisOptionLoader $config)
    {
    }

    /**
     * @return Redis The exclusive native connection for the named item; null selects the default item
     *
     * @throws Throwable When configuration validation or connection acquisition fails
     */
    public function get(?string $name = null, ?int $database = null): Redis
    {
        return $this->pool->get($this->config->get($name), $database);
    }
}
