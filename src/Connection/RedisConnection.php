<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Connection;

use InvalidArgumentException;
use Redis;
use RedisException;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Exception\RedisConnectionException;

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
        NativeClientSetup::connect($this->redis, $this->option, $this->database, $this->connected);
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
        NativeClientCleanup::close($this->redis, $this->connected);
        NativeClientSetup::connect($this->redis, $this->option, $this->database, $this->connected);
    }

    /** @throws RedisConnectionException When closing the native connection fails */
    public function close(): void
    {
        NativeClientCleanup::close($this->redis, $this->connected);
    }
}
