<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use Throwable;

/**
 * Retains the first deferred cleanup failure of a Redis pool until the next acquisition or shutdown takes it.
 *
 * @internal
 */
final class PendingFailure
{
    private ?Throwable $failure = null;

    public function record(Throwable $failure): void
    {
        $this->failure ??= $failure;
    }

    public function take(): ?Throwable
    {
        $failure = $this->failure;
        $this->failure = null;

        return $failure;
    }
}
