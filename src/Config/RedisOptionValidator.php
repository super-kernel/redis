<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Config;

use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;

use function is_finite;
use function str_contains;
use function trim;

/** Validates connection settings without replacing the application's configuration object. */
final class RedisOptionValidator
{
    /** @throws InvalidRedisConfigurationException When connection or pool settings are invalid */
    public static function validate(RedisConfig $config): void
    {
        if ('' === trim($config->host) || str_contains($config->host, "\0")) {
            throw new InvalidRedisConfigurationException('The Redis host must be non-empty and contain no NUL bytes.');
        }

        if (1 > $config->port || 65535 < $config->port) {
            throw new InvalidRedisConfigurationException('The Redis TCP port must be between 1 and 65535.');
        }

        if (0 > $config->database) {
            throw new InvalidRedisConfigurationException('The Redis database index must be non-negative.');
        }

        $username = $config->username;

        if (null !== $username && ('' === $username || null === $config->password)) {
            throw new InvalidRedisConfigurationException(
                'A Redis ACL username requires a password and cannot be empty.',
            );
        }

        foreach ([
            'connectTimeout' => $config->connectTimeout,
            'readTimeout'    => $config->readTimeout,
        ] as $name => $timeout) {
            if (!is_finite($timeout) || 0.000001 > $timeout) {
                throw new InvalidRedisConfigurationException(
                    'Redis ' . $name . ' must be finite and at least one microsecond.',
                );
            }
        }

        if (1 > $config->poolOption->maxConnections()) {
            throw new InvalidRedisConfigurationException('The Redis pool must allow at least one connection.');
        }

        if (!is_finite($config->poolOption->waitTimeout()) || 0 > $config->poolOption->waitTimeout()) {
            throw new InvalidRedisConfigurationException(
                'The Redis pool wait timeout must be finite and non-negative.',
            );
        }

        if ($config->poolOption->capacity()->capacity() !== $config->poolOption->maxConnections()) {
            throw new InvalidRedisConfigurationException('The Redis pool capacity must match its connection limit.');
        }
    }
}
