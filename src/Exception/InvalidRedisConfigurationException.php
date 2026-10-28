<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Exception;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

final class InvalidRedisConfigurationException extends InvalidArgumentException implements
    RedisExceptionInterface,
    ContainerExceptionInterface
{
}
