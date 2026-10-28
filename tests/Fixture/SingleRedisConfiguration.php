<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Redis\Config\RedisConfig;
use Throwable;

#[Configuration(RedisConfig::class)]
final class SingleRedisConfiguration implements MultipleConfigInterface
{
    public string $host = 'redis-service-that-does-not-exist.invalid';

    public int $calls = 0;

    /** @throws Throwable When assembling the option fails */
    #[Configuration]
    public function connection(): RedisConfig
    {
        ++$this->calls;

        return RedisOptions::create(host: $this->host);
    }
}
