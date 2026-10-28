<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use LogicException;
use SuperKernel\Pool\Contract\ConnectionInterface;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Redis\Connection\RedisConnection;
use Throwable;

/** @implements PoolInterface<RedisConnection> */
final class RecordingPool implements PoolInterface
{
    private bool $closed = false;
    public ?Throwable $discardFailure = null;

    /** @var list<ConnectionInterface> */
    public array $released = [];

    /** @var list<ConnectionInterface> */
    public array $discarded = [];

    public function __construct(public readonly RedisConnection $connection)
    {
    }

    public function acquire(?float $timeout = null): ConnectionInterface
    {
        return $this->connection;
    }

    public function release(ConnectionInterface $connection): void
    {
        $this->released[] = $connection;
    }

    public function discard(ConnectionInterface $connection): void
    {
        $this->discarded[] = $connection;
        if (null !== $this->discardFailure) {
            throw $this->discardFailure;
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function maintain(int $minimumIdle = 0): void
    {
        throw new LogicException('The recording pool does not support maintenance.');
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
