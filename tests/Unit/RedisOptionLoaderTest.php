<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use SuperKernel;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;
use SuperKernel\Redis\Tests\Fixture\ConfigurationRegistry;
use SuperKernel\Redis\Tests\Fixture\RedisOptions;
use Throwable;

use const INF;
use const NAN;

final class RedisOptionLoaderTest extends TestCase
{
    /** @return iterable<string, array{string, string|int|float|null}> */
    public static function invalidValues(): iterable
    {
        yield 'empty host' => [
            'host',
            '',
        ];
        yield 'blank host' => [
            'host',
            '   ',
        ];
        yield 'port below range' => [
            'port',
            0,
        ];
        yield 'port above range' => [
            'port',
            65536,
        ];
        yield 'negative database' => [
            'database',
            -1,
        ];
        yield 'username without password' => [
            'username',
            'user',
        ];
        yield 'zero connect timeout' => [
            'connectTimeout',
            0.0,
        ];
        yield 'sub-microsecond connect timeout' => [
            'connectTimeout',
            0.0000000001,
        ];
        yield 'negative connect timeout' => [
            'connectTimeout',
            -0.1,
        ];
        yield 'infinite connect timeout' => [
            'connectTimeout',
            INF,
        ];
        yield 'NaN connect timeout' => [
            'connectTimeout',
            NAN,
        ];
        yield 'zero read timeout' => [
            'readTimeout',
            0.0,
        ];
        yield 'sub-microsecond read timeout' => [
            'readTimeout',
            0.0000000001,
        ];
        yield 'negative read timeout' => [
            'readTimeout',
            -0.1,
        ];
        yield 'infinite read timeout' => [
            'readTimeout',
            INF,
        ];
        yield 'NaN read timeout' => [
            'readTimeout',
            NAN,
        ];
        yield 'zero capacity' => [
            'maxConnections',
            0,
        ];
        yield 'negative capacity' => [
            'maxConnections',
            -1,
        ];
        yield 'negative wait timeout' => [
            'waitTimeout',
            -0.1,
        ];
        yield 'infinite wait timeout' => [
            'waitTimeout',
            INF,
        ];
        yield 'NaN wait timeout' => [
            'waitTimeout',
            NAN,
        ];
    }

    /** @throws Throwable When the provider fails */
    public function testReturnsTheSameValidatedConfigurationSource(): void
    {
        $source = RedisOptions::create(username: 'application', password: 'secret', waitTimeout: 0.0);
        self::assertSame($source, $this->resolve($source));
    }

    /** @throws Throwable When the provider fails */
    public function testRequiresAnApplicationConfigurationDeclaration(): void
    {
        $this->expectException(ConfigurationNotFoundException::class);

        new RedisOptionLoader(new ConfigurationRegistry([]))->get();
    }

    /** @throws Throwable When the provider fails */
    public function testRejectsARegisteredSourceWithTheWrongType(): void
    {
        $this->expectException(InvalidRedisConfigurationException::class);

        new RedisOptionLoader(new ConfigurationRegistry([
            SuperKernel\Redis\Config\RedisConfig::class => new stdClass(),
        ]))->get();
    }

    /** @throws Throwable When the provider fails */
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidConnectionAndPoolSettings(string $field, string|int|float|null $value): void
    {
        $source = RedisOptions::create(...[
            $field => $value,
        ]);
        $this->expectException(InvalidRedisConfigurationException::class);

        $this->resolve($source);
    }

    /** @throws Throwable When the provider fails */
    private function resolve(RedisConfig $source): RedisConfig
    {
        return new RedisOptionLoader(new ConfigurationRegistry([
            SuperKernel\Redis\Config\RedisConfig::class => $source,
        ]))->get();
    }
}
