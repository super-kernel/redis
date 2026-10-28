<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use InvalidArgumentException;
use LogicException;
use Redis;
use SuperKernel\Attribute\Provider;
use SuperKernel\Pool\Contract\PoolInterface;
use SuperKernel\Pool\Exception\PoolException;
use SuperKernel\Pool\Pool;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionValidator;
use SuperKernel\Redis\Connection\RedisConnection;
use SuperKernel\Redis\Exception\RedisConnectionException;
use SuperKernel\Redis\Factory\RedisConnectionFactory;
use SuperKernel\Redis\Session\RedisSession;
use Swoole\Coroutine;
use Swoole\Coroutine\Context;
use Swoole\Thread;
use Throwable;
use WeakMap;

use function class_exists;
use function getmypid;
use function spl_object_id;

/** Owns configuration-specific pools and their exclusive coroutine sessions. */
#[Provider(RedisPool::class)]
final class RedisPool
{
    /** @var array<string, PoolInterface<RedisConnection>> */
    private array $pools = [];

    /** @var array<string, RedisConfig> Retains option identities for owned pools */
    private array $options = [];

    private bool $closed = false;

    private ?Throwable $pendingFailure = null;

    private readonly string $owner;

    public function __construct()
    {
        $this->owner = self::owner();
    }

    /**
     * The same option and database share one connection within a coroutine.
     * Database sessions share the capacity of the unchanged supplied PoolOption.
     *
     * @return Redis Native client whose use must finish before the owning coroutine exits
     *
     * @throws InvalidArgumentException When the database is negative or one pool option has conflicting configurations
     * @throws Throwable                When validation, borrowing, or deferred cleanup fails
     */
    public function get(RedisConfig $option, ?int $database = null): Redis
    {
        $this->assertOpen();
        $database ??= $option->database;
        if (0 > $database) {
            throw new InvalidArgumentException('The Redis database index must not be negative.');
        }

        $context = Coroutine::getContext();
        if (null === $context) {
            throw new PoolException('Redis connection acquisition requires an active Swoole coroutine.');
        }

        $poolKey = 'option:' . spl_object_id($option->poolOption);
        if (isset($this->options[$poolKey]) && $this->options[$poolKey] !== $option) {
            throw new InvalidArgumentException('A pool option cannot be reused with another Redis configuration.');
        }
        $key = $poolKey . ':' . $database;
        $storage = self::sessions($context);
        $sessions = $storage[$this] ?? [];
        if (!isset($sessions[$key])) {
            if (!isset($this->pools[$poolKey])) {
                RedisOptionValidator::validate($option);
                $factory = $option->poolOption->factory();
                if ($factory instanceof RedisConnectionFactory) {
                    $factory->bind($option);
                }
                $this->pools[$poolKey] = new Pool($option->poolOption);
                $this->options[$poolKey] = $option;
            }
            $pool = $this->pools[$poolKey];
            $connection = $pool->acquire();

            try {
                if (!$connection instanceof RedisConnection) {
                    throw new RedisConnectionException('The Redis pool returned an incompatible connection.');
                }
                if (true !== $connection->client()->select($database)) {
                    throw new RedisConnectionException('Selecting the Redis database failed.');
                }
                $this->assertOpen();
                $session = new RedisSession($connection, $pool);
            } catch (Throwable $exception) {
                try {
                    $pool->discard($connection);
                } catch (Throwable $cleanupFailure) {
                    $this->pendingFailure ??= $cleanupFailure;
                }
                throw $exception;
            }

            $sessions[$key] = $session;
            $storage[$this] = $sessions;
            Coroutine::defer(function () use ($session): void {
                try {
                    $session->close();
                } catch (Throwable $exception) {
                    $this->pendingFailure ??= $exception;
                }
            });
        }

        return $sessions[$key]->client();
    }

    /**
     * Retire a connection owned by the current coroutine. Its session stays terminal until coroutine exit.
     * When a primary failure is supplied, retain any cleanup failure for the next acquisition or shutdown.
     *
     * @throws Throwable When ownership or cleanup fails without a supplied primary failure
     */
    public function discard(Redis $redis, ?Throwable $primaryFailure = null): void
    {
        try {
            $this->assertOwner();
            $context = Coroutine::getContext();
            if (null !== $context) {
                foreach (self::sessions($context)[$this] ?? [] as $session) {
                    if ($session->redis === $redis) {
                        $session->close();

                        return;
                    }
                }
            }
            throw new PoolException('The Redis connection does not belong to the current coroutine.');
        } catch (Throwable $exception) {
            if (null === $primaryFailure) {
                throw $exception;
            }
            $this->pendingFailure ??= $exception;
        }
    }

    /**
     * Permanently close every pool. Existing native borrowers finish before coroutine teardown retires them.
     * Repeated calls retry failed connection cleanup, including failures after an earlier shutdown pass.
     *
     * @throws Throwable When a deferred cleanup, owning-context check, or pool shutdown fails
     */
    public function close(): void
    {
        $this->assertOwner();
        $failure = $this->takePendingFailure();

        $this->closed = true;

        foreach ($this->pools as $pool) {
            try {
                $pool->close();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        $pendingFailure = $this->takePendingFailure();
        $failure ??= $pendingFailure;

        if (null !== $failure) {
            throw $failure;
        }
    }

    /** @return WeakMap<RedisPool, array<string, RedisSession>> */
    private static function sessions(Context $context): WeakMap
    {
        $sessions = $context[self::class] ?? null;

        if (!$sessions instanceof WeakMap) {
            $sessions = new WeakMap();
            $context[self::class] = $sessions;
        }

        return $sessions;
    }

    /** @throws Throwable When shutdown, a foreign runtime owner, or deferred cleanup prevents further use */
    private function assertOpen(): void
    {
        $this->assertOwner();
        $failure = $this->takePendingFailure();

        if (null !== $failure) {
            throw $failure;
        }

        if ($this->closed) {
            throw new LogicException('The Redis pool is closed.');
        }
    }

    /** @throws PoolException When a pool is used outside its owning process or thread */
    private function assertOwner(): void
    {
        if ($this->owner !== self::owner()) {
            throw new PoolException('A Redis pool cannot be shared between processes or threads.');
        }
    }

    private function takePendingFailure(): ?Throwable
    {
        $failure = $this->pendingFailure;
        $this->pendingFailure = null;

        return $failure;
    }

    private static function owner(): string
    {
        return getmypid() . ':' . (class_exists(Thread::class) ? Thread::getId() : 0);
    }
}
