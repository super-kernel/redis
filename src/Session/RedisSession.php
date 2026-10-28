<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Session;

use LogicException;
use Redis;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Exception\RedisConnectionException;
use Throwable;

/** @internal Owns one exclusive native client until its coroutine finishes. */
final class RedisSession
{
    private bool $closed = false;

    public readonly Redis $redis;

    /**
     * @param PoolInterface<RedisConnection> $pool
     *
     * @throws RedisConnectionException When the acquired connection is not established
     */
    public function __construct(private readonly RedisConnection $connection, private readonly PoolInterface $pool)
    {
        $this->redis = $connection->client();
    }

    /**
     * @return Redis The native client while its session remains active
     *
     * @throws LogicException When this coroutine's session has already been retired
     */
    public function client(): Redis
    {
        if ($this->closed) {
            throw new LogicException('The native Redis session has already been retired.');
        }

        return $this->redis;
    }

    /** @throws Throwable When retiring the native connection fails */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->pool->discard($this->connection);
    }

}
