<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Pool;

use LogicException;
use Throwable;

/**
 * Rejects Redis pool acquisition after shutdown, outside the owning process or thread, or with a pending failure.
 *
 * @internal
 */
final class AcquisitionGuard
{
    private bool $closed = false;

    public function __construct(private readonly string $owner, private readonly PendingFailure $pendingFailure)
    {
    }

    /** @throws Throwable When shutdown, a foreign runtime owner, or deferred cleanup prevents further use */
    public function assertOpen(): void
    {
        OwnerGuard::assert($this->owner);
        $failure = $this->pendingFailure->take();

        if (null !== $failure) {
            throw $failure;
        }

        if ($this->closed) {
            throw new LogicException('The Redis pool is closed.');
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
