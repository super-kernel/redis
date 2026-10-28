<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Fixture;

use SuperKernel\Contract\ConfigInterface;

use function array_key_exists;

final readonly class ConfigurationRegistry implements ConfigInterface
{
    /** @param array<class-string, object> $entries */
    public function __construct(private array $entries)
    {
    }

    public function has(string $interface, ?string $name = null): bool
    {
        return (null === $name || 'default' === $name) && array_key_exists($interface, $this->entries);
    }

    public function get(string $interface, ?string $name = null): ?object
    {
        return $this->has($interface, $name) ? $this->entries[$interface] : null;
    }
}
