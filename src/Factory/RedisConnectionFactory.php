<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Factory;

use InvalidArgumentException;
use LogicException;
use Redis;
use SuperKernel\Pool\Contract\ConnectionFactoryInterface;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Connection\RedisConnection;

/** @implements ConnectionFactoryInterface<RedisConnection> */
final readonly class RedisConnectionFactory implements ConnectionFactoryInterface
{
    private RedisConfig $option;

    /** @throws InvalidArgumentException When an explicit database index is negative */
    public function __construct(?RedisConfig $option = null, private ?int $database = null)
    {
        if (null !== $database && 0 > $database) {
            throw new InvalidArgumentException('The Redis database index must not be negative.');
        }
        if (null !== $option) {
            $this->option = $option;
        }
    }

    /**
     * Bind after RedisConfig assembly so its PoolOption can contain this factory without a configuration copy.
     *
     * @throws InvalidArgumentException When the factory already belongs to another option
     */
    public function bind(RedisConfig $option): void
    {
        if (isset($this->option) && $this->option !== $option) {
            throw new InvalidArgumentException('A Redis connection factory belongs to one Redis option.');
        }
        if (!isset($this->option)) {
            $this->option = $option;
        }
    }

    /** @throws LogicException When no Redis option has been bound before connection creation */
    public function create(): RedisConnection
    {
        if (!isset($this->option)) {
            throw new LogicException('Bind a Redis option before creating a connection.');
        }

        return new RedisConnection($this->option, new Redis(), $this->database);
    }
}
