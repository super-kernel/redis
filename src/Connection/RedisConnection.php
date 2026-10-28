<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Connection;

use InvalidArgumentException;
use Redis;
use RedisException;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Exception\RedisConnectionException;
use Throwable;

final class RedisConnection implements ConnectionInterface
{
    private bool $connected = false;

    /** @throws InvalidArgumentException When an explicit database index is negative */
    public function __construct(
        private readonly RedisConfig $option,
        private readonly Redis       $redis,
        private readonly ?int        $database = null,
    )
    {
        if (null !== $database && 0 > $database) {
            throw new InvalidArgumentException('The Redis database index must not be negative.');
        }
    }

    /** @throws RedisConnectionException When the connection cannot be established or configured */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        try {
            if (!$this->redis->connect(
                $this->option->host,
                $this->option->port,
                $this->option->connectTimeout,
                null,
                0,
                $this->option->readTimeout,
            )) {
                throw new RedisConnectionException('Redis connection establishment failed.');
            }

            if (!$this->redis->setOption(Redis::OPT_MAX_RETRIES, 0)) {
                throw new RedisConnectionException('Disabling Redis automatic reconnection failed.');
            }

            if (!$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE)) {
                throw new RedisConnectionException('Configuring the Redis serializer failed.');
            }

            $password = $this->option->password;

            if (null !== $password) {
                $username = $this->option->username;
                $credentials = null === $username ? $password : [
                    $username,
                    $password,
                ];

                if (true !== $this->redis->auth($credentials)) {
                    throw new RedisConnectionException('Redis authentication failed.');
                }
            }

            if (true !== $this->redis->select($this->database ?? $this->option->database)) {
                throw new RedisConnectionException('Selecting the Redis database failed.');
            }

            $this->connected = true;
        } catch (Throwable $exception) {
            try {
                $this->close();
            } catch (Throwable) {
                // Cleanup must not replace the original connection failure.
            }

            throw new RedisConnectionException('Redis connection initialization failed.', 0, $exception);
        }
    }

    /**
     * Return the native client for the current exclusive borrower.
     *
     * @return Redis Connected client owned by this connection
     *
     * @throws RedisConnectionException When no established connection is available
     */
    public function client(): Redis
    {
        if (!$this->connected || !$this->redis->isConnected()) {
            throw new RedisConnectionException('The Redis connection is not established.');
        }

        return $this->redis;
    }

    public function isHealthy(): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            return $this->redis->isConnected() && true === $this->redis->ping();
        } catch (RedisException) {
            return false;
        }
    }

    /** @throws RedisConnectionException When cleanup or connection establishment fails */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /** @throws RedisConnectionException When closing the native connection fails */
    public function close(): void
    {
        $this->connected = false;

        try {
            if ($this->redis->isConnected() && !$this->redis->close()) {
                throw new RedisConnectionException('Closing the Redis connection failed.');
            }
        } catch (Throwable $exception) {
            throw new RedisConnectionException('Redis connection cleanup failed.', 0, $exception);
        }
    }
}
