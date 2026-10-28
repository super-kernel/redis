<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Exception;

use RuntimeException;

class RedisException extends RuntimeException implements RedisExceptionInterface
{
}
