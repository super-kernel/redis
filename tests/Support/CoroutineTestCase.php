<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Support;

use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Swoole\Coroutine\run;

abstract class CoroutineTestCase extends TestCase
{
    /**
     * @param Closure(): void $test
     *
     * @throws Throwable When the supplied coroutine test fails
     */
    protected function runCoroutine(Closure $test): void
    {
        $failure = null;
        run(static function () use ($test, &$failure): void {
            try {
                $test();
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });

        if (null !== $failure) {
            throw $failure;
        }
    }
}
