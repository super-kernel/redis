<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Redis

`super-kernel/redis` gives SuperKernel coroutines pooled phpredis connections to single Redis servers, configured as named items and used as native `\Redis` objects or through a static proxy.

## Installation

```bash
composer require super-kernel/redis
```

The package needs the `redis` extension `>=6.3.0 <6.4` and, at run time, the Swoole extension, because connections are borrowed inside coroutines. It uses `super-kernel/pool` for pooling and `super-kernel/config` to find the configuration, and needs SKERNEL to build the project.

## What it provides

- Named connection settings declared as `RedisConfig` items, one of them the default.
- `RedisFactory::get($name, $database)`, which returns a native `\Redis` connection of the current coroutine.
- One pool per configuration item, with the limits and policies of its `PoolOption`.
- One connection per item and database for each coroutine, returned to the pool when the coroutine ends.
- The `Redis` proxy, which forwards static and instance calls to the default connection.
- Validation of host, port, database, credentials, timeouts, and pool limits before any connection is opened.

It does not provide:

- Redis Cluster, Sentinel, TLS, or persistent connections;
- automatic failover, reconnection, or repeated commands;
- PSR-6 or PSR-16 cache adapters;
- serialization of values, which start with the serializer disabled;
- sharing of connections between coroutines, processes, or threads.

## Quick start

In an application created from `super-kernel/skeleton` with `super-kernel/redis` installed and Redis listening on `127.0.0.1:6379`, declare the connection. Save it as `src/Config/RedisConfigurations.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Config;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Factory\RedisConnectionFactory;

#[Configuration(RedisConfig::class)]
final class RedisConfigurations implements MultipleConfigInterface
{
    #[Configuration]
    public function primary(): RedisConfig
    {
        return new RedisConfig(
            poolOption: new PoolOption(
                factory: new RedisConnectionFactory(),
                capacity: new CoroutineCapacity(8),
                maxConnections: 8,
            ),
            host: '127.0.0.1',
            port: 6379,
        );
    }
}
```

Use a connection inside a coroutine. Save the script as `bin/greeting.php`:

```php
<?php

declare(strict_types=1);

use SuperKernel\Context\ApplicationContext;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;

use function Swoole\Coroutine\run;

require dirname(__DIR__) . '/vendor/autoload.php';

run(static function (): void {
    $container = ApplicationContext::getContainer();

    $redis = $container->get(RedisFactory::class)->get();
    $redis->set('greeting', 'Hello from Redis');

    echo $redis->get('greeting'), PHP_EOL;

    $container->get(RedisPool::class)->close();
});
```

Build the project and run the script:

```bash
skernel build
php bin/greeting.php
```

It prints `Hello from Redis`.

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Attribute\Configuration` | Attribute | Declares the `RedisConfig` source class and marks each method that returns an item, with `default: true` for the default. |
| `SuperKernel\Contract\MultipleConfigInterface` | Interface | Implemented by the source class whose methods return named items. |
| `SuperKernel\Redis\Config\RedisConfig` | Class | One connection's pool option, host, port, database, credentials, and timeouts. |
| `SuperKernel\Redis\Factory\RedisConnectionFactory` | Class | The connection factory to put in each item's `PoolOption`. |
| `SuperKernel\Redis\Factory\RedisFactory` | Class | `get($name, $database)` returns the coroutine's native `\Redis` connection. |
| `SuperKernel\Redis\Proxy\Redis` | Class | Forwards static and instance calls to the default connection. |
| `SuperKernel\Redis\Pool\RedisPool` | Class | Owns the pools; `discard()` retires a connection and `close()` shuts every pool down. |
| `SuperKernel\Redis\Config\RedisOptionLoader` | Class | `get($name)` returns a validated configuration item. |

## Behavior to know

- Connections are borrowed only inside a coroutine; elsewhere `get()` raises `SuperKernel\Pool\Exception\PoolException` with `Redis connection acquisition requires an active Swoole coroutine.` No connection is opened before the first `get()`.
- An unknown name raises `SuperKernel\Config\Exception\ConfigurationNotFoundException` with `No Redis configuration item is registered for this name.`; with several items and no default, only unnamed access fails.
- Invalid settings raise `SuperKernel\Redis\Exception\InvalidRedisConfigurationException`, for example `The Redis TCP port must be between 1 and 65535.`, before a pool is created.
- A connection holds its pool slot until its coroutine ends, so a long-running coroutine keeps it; transactions, pipelines, the selected database, and options stay on that connection until then.
- A proxy call that throws retires its connection; later use of the same item and database in that coroutine raises `The native Redis session has already been retired.`
- The proxy works only after the container has created `RedisFactory`; before that it raises `The Redis proxy requires initialization through RedisFactoryProvider.` After `RedisPool::close()`, every borrow raises `The Redis pool is closed.`
- Configuration items are found in the build metadata: run `skernel build` again after adding, removing, or changing them, or start with `skernel run`, which builds first.

## Documentation

The developer documentation covers configuration items, the factory and sessions, the proxy, pooling across databases, shutdown and cleanup, the error messages, and what not to do: [SuperKernel Redis](https://github.com/super-kernel/.github/blob/main/docs/components/redis.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
