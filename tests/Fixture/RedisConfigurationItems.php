<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Redis\Config\RedisConfig;
use Throwable;

#[Configuration(RedisConfig::class)]
final class RedisConfigurationItems implements MultipleConfigInterface
{
    public int $port = 6379;
    public int $maxConnections = 1;
    public string $cacheHost = '127.0.0.1';

    /** @var array<string, int> */
    public array $calls = [];

    /** @throws Throwable When assembling the primary option fails */
    #[Configuration(default: true)]
    public function primary(): RedisConfig
    {
        $this->calls['primary'] = ($this->calls['primary'] ?? 0) + 1;

        return RedisOptions::create(port: $this->port, maxConnections: $this->maxConnections);
    }

    /** @throws Throwable When assembling the cache option fails */
    #[Configuration]
    public function cache(): RedisConfig
    {
        $this->calls['cache'] = ($this->calls['cache'] ?? 0) + 1;

        return RedisOptions::create(
            host: $this->cacheHost,
            port: $this->port,
            database: 1,
            maxConnections: $this->maxConnections,
        );
    }

    /** @throws Throwable When assembling the independent option fails */
    #[Configuration]
    public function independent(): RedisConfig
    {
        return RedisOptions::create(port: $this->port, maxConnections: $this->maxConnections);
    }
}
