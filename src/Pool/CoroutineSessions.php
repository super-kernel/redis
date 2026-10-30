<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use SuperKernel\Redis\Session\RedisSession;
use Swoole\Coroutine\Context;
use WeakMap;

/**
 * Keeps the native sessions of every Redis pool in the current coroutine context.
 *
 * @internal
 */
final class CoroutineSessions
{
    /** @return WeakMap<RedisPool, array<string, RedisSession>> */
    public static function of(Context $context): WeakMap
    {
        $sessions = $context[RedisPool::class] ?? null;

        if (!$sessions instanceof WeakMap) {
            $sessions = new WeakMap();
            $context[RedisPool::class] = $sessions;
        }

        return $sessions;
    }
}
