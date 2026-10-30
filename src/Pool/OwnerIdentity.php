<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use Swoole\Thread;

use function class_exists;
use function getmypid;

/**
 * Identifies the current process and thread as the owner of a Redis pool.
 *
 * @internal
 */
final class OwnerIdentity
{
    public static function current(): string
    {
        return getmypid() . ':' . (class_exists(Thread::class) ? Thread::getId() : 0);
    }
}
