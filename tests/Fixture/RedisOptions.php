<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Factory\RedisConnectionFactory;
use Throwable;

use function max;

final class RedisOptions
{
    /**
     * @param PoolOption<RedisConnection>|null $poolOption
     *
     * @throws Throwable When assembling pool collaborators fails
     */
    public static function create(
        string      $host = '127.0.0.1',
        int         $port = 6379,
        int         $database = 0,
        ?string     $username = null,
        ?string     $password = null,
        float       $connectTimeout = 1.0,
        float       $readTimeout = 1.0,
        int         $maxConnections = 2,
        float       $waitTimeout = 0.1,
        ?PoolOption $poolOption = null,
    ): RedisConfig
    {
        return new RedisConfig(
            poolOption: $poolOption ?? new PoolOption(
                factory: new RedisConnectionFactory(),
                capacity: new CoroutineCapacity(max(1, $maxConnections)),
                maxConnections: $maxConnections,
                waitTimeout: $waitTimeout,
            ),
            host: $host,
            port: $port,
            database: $database,
            username: $username,
            password: $password,
            connectTimeout: $connectTimeout,
            readTimeout: $readTimeout,
        );
    }
}
