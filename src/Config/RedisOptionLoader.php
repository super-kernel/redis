<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Config;

use Psr\Container\ContainerExceptionInterface;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;

/** Applies Redis validation to named items owned and retained by Config. */
final readonly class RedisOptionLoader
{
    public function __construct(private ConfigInterface $config)
    {
    }

    /**
     * @return RedisConfig The unchanged Config-owned option; null selects its declared default
     *
     * @throws ContainerExceptionInterface When an item cannot be loaded or violates the Redis contract
     */
    public function get(?string $name = null): RedisConfig
    {
        $configuration = $this->config->get(RedisConfig::class, $name)
            ?? throw new ConfigurationNotFoundException('No Redis configuration item is registered for this name.');

        if (!$configuration instanceof RedisConfig) {
            throw new InvalidRedisConfigurationException('The Redis configuration item must be a RedisConfig.');
        }

        RedisOptionValidator::validate($configuration);

        return $configuration;
    }
}
