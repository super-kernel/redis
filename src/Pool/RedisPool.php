<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use InvalidArgumentException;
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
use Throwable;

use function spl_object_id;

/** Owns configuration-specific pools and their exclusive coroutine sessions. */
#[Provider(RedisPool::class)]
final class RedisPool
{
    /** @var array<string, PoolInterface<RedisConnection>> */
    private array $pools = [];

    /** @var array<string, RedisConfig> Retains option identities for owned pools */
    private array $options = [];

    private readonly PendingFailure $pendingFailure;

    private readonly AcquisitionGuard $acquisitionGuard;

    private readonly string $owner;

    public function __construct()
    {
        $this->owner = OwnerIdentity::current();
        $this->pendingFailure = new PendingFailure();
        $this->acquisitionGuard = new AcquisitionGuard($this->owner, $this->pendingFailure);
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
        $this->acquisitionGuard->assertOpen();
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
        $storage = CoroutineSessions::of($context);
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
                $this->acquisitionGuard->assertOpen();
                $session = new RedisSession($connection, $pool);
            } catch (Throwable $exception) {
                try {
                    $pool->discard($connection);
                } catch (Throwable $cleanupFailure) {
                    $this->pendingFailure->record($cleanupFailure);
                }
                throw $exception;
            }

            $sessions[$key] = $session;
            $storage[$this] = $sessions;
            Coroutine::defer(function () use ($session): void {
                try {
                    $session->close();
                } catch (Throwable $exception) {
                    $this->pendingFailure->record($exception);
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
            OwnerGuard::assert($this->owner);
            $context = Coroutine::getContext();
            if (null !== $context) {
                foreach (CoroutineSessions::of($context)[$this] ?? [] as $session) {
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
            $this->pendingFailure->record($exception);
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
        OwnerGuard::assert($this->owner);
        $failure = $this->pendingFailure->take();

        $this->acquisitionGuard->close();

        foreach ($this->pools as $pool) {
            try {
                $pool->close();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        $pendingFailure = $this->pendingFailure->take();
        $failure ??= $pendingFailure;

        if (null !== $failure) {
            throw $failure;
        }
    }
}
