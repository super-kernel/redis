<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Config;

use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Redis\Connection\RedisConnection;

/** Connection settings and pool collaborators retained as one Config-owned item without connection I/O. */
final readonly class RedisConfig
{
    /** @param PoolOption<RedisConnection> $poolOption Pool behavior and collaborators for this option */
    public function __construct(
        public PoolOption $poolOption,
        public string     $host = '127.0.0.1',
        public int        $port = 6379,
        public int        $database = 0,
        public ?string    $username = null,
        public ?string    $password = null,
        public float      $connectTimeout = 1.0,
        public float      $readTimeout = 1.0,
    )
    {
    }
}
