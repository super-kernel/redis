<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use SuperKernel\Pool\Exception\PoolException;

/**
 * Rejects Redis pool use outside the process and thread that created the pool.
 *
 * @internal
 */
final class OwnerGuard
{
    /** @throws PoolException When a pool is used outside its owning process or thread */
    public static function assert(string $owner): void
    {
        if ($owner !== OwnerIdentity::current()) {
            throw new PoolException('A Redis pool cannot be shared between processes or threads.');
        }
    }
}
