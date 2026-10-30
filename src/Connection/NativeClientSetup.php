<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Connection;

use Redis;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Exception\RedisConnectionException;
use Throwable;

/**
 * Establishes and configures the native client of a pooled Redis connection, cleaning up a partial connection.
 *
 * @internal
 */
final class NativeClientSetup
{
    /**
     * @param bool $connected The owning connection's established state; setup is skipped while it is true and it
     *                        becomes true only after authentication and database selection succeed
     *
     * @throws RedisConnectionException When the connection cannot be established or configured
     */
    public static function connect(Redis $redis, RedisConfig $option, ?int $database, bool &$connected): void
    {
        if ($connected) {
            return;
        }

        try {
            if (!$redis->connect(
                $option->host,
                $option->port,
                $option->connectTimeout,
                null,
                0,
                $option->readTimeout,
            )) {
                throw new RedisConnectionException('Redis connection establishment failed.');
            }

            if (!$redis->setOption(Redis::OPT_MAX_RETRIES, 0)) {
                throw new RedisConnectionException('Disabling Redis automatic reconnection failed.');
            }

            if (!$redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE)) {
                throw new RedisConnectionException('Configuring the Redis serializer failed.');
            }

            $password = $option->password;

            if (null !== $password) {
                $username = $option->username;
                $credentials = null === $username ? $password : [
                    $username,
                    $password,
                ];

                if (true !== $redis->auth($credentials)) {
                    throw new RedisConnectionException('Redis authentication failed.');
                }
            }

            if (true !== $redis->select($database ?? $option->database)) {
                throw new RedisConnectionException('Selecting the Redis database failed.');
            }

            $connected = true;
        } catch (Throwable $exception) {
            try {
                NativeClientCleanup::close($redis, $connected);
            } catch (Throwable) {
                // Cleanup must not replace the original connection failure.
            }

            throw new RedisConnectionException('Redis connection initialization failed.', 0, $exception);
        }
    }
}
