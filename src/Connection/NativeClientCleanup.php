<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Connection;

use Redis;
use SuperKernel\Redis\Exception\RedisConnectionException;
use Throwable;

/**
 * Marks a pooled Redis connection as not established and closes its native client.
 *
 * @internal
 */
final class NativeClientCleanup
{
    /**
     * @param bool $connected The owning connection's established state, cleared before the native client closes
     *
     * @throws RedisConnectionException When closing the native connection fails
     */
    public static function close(Redis $redis, bool &$connected): void
    {
        $connected = false;

        try {
            if ($redis->isConnected() && !$redis->close()) {
                throw new RedisConnectionException('Closing the Redis connection failed.');
            }
        } catch (Throwable $exception) {
            throw new RedisConnectionException('Redis connection cleanup failed.', 0, $exception);
        }
    }
}
