# SuperKernel Redis

`super-kernel/redis` integrates single-server phpredis connections with exclusive coroutine sessions and the
SuperKernel Pool component. It exposes native `\Redis` clients and transparent instance/static proxies.

## Options discovered by Config

`SuperKernel\Redis\Config\RedisConfig` is a `final readonly` class. All its properties are public:
`poolOption`, `host`, `port`, `database`, `username`, `password`, `connectTimeout`, and `readTimeout`.
Read settings as properties, for example `$option->host` and `$option->poolOption`.
The required `PoolOption` contains the exact connection factory, capacity coordinator, limits, health mode, and
frequency policy to use. Redis does not copy it or create a second set of pool settings.

Config discovers, names, selects defaults, lazily creates, and retains one or more `RedisConfig` items.
DI supplies services such as `RedisFactory`, `RedisPool`, and `RedisOptionLoader`; Redis options are not registered
as default DI entries. `RedisOptionLoader` obtains each requested item from `ConfigInterface`, validates its
connection and pool settings, and returns that same object without opening a connection.

Declare an application source with `#[Configuration(RedisConfig::class)]` and `MultipleConfigInterface`.
Each public, parameterless method marked with `#[Configuration]` returns one option. This source form supports
both a single option and several named options:

```php
use SuperKernel\Attribute\Configuration;
use SuperKernel\Contract\MultipleConfigInterface;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Constants\HealthCheckMode;
use SuperKernel\Pool\Frequency\SmartLowFrequency;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Factory\RedisConnectionFactory;

#[Configuration(RedisConfig::class)]
final class RedisConfigurations implements MultipleConfigInterface
{
    #[Configuration(default: true)]
    public function primary(): RedisConfig
    {
        return new RedisConfig(
            poolOption: new PoolOption(
                factory: new RedisConnectionFactory(),
                capacity: new CoroutineCapacity(8),
                maxConnections: 8,
                waitTimeout: 0.25,
                healthChecks: HealthCheckMode::OnBorrowAndReturn,
                frequency: new SmartLowFrequency(),
            ),
            host: '127.0.0.1',
            port: 6379,
            database: 0,
            connectTimeout: 1.0,
            readTimeout: 1.0,
        );
    }

    #[Configuration]
    public function cache(): RedisConfig
    {
        return new RedisConfig(
            poolOption: new PoolOption(
                factory: new RedisConnectionFactory(),
                capacity: new CoroutineCapacity(4),
                maxConnections: 4,
            ),
            database: 1,
        );
    }
}
```

Method names are case-sensitive item names. A source with only one marked method needs no default flag.
With several items, `default: true` selects the unnamed default. Without a default, named lookups still work;
only unnamed access fails. Unknown names never fall back to another item.

A consumer of the injected `ConfigInterface` can inspect the retained option directly:

```php
$primary = $config->get(RedisConfig::class);
$same = $config->get(RedisConfig::class, 'primary');
$cache = $config->get(RedisConfig::class, 'cache');
```

For validated options, use the injected `RedisOptionLoader::get($name)`; use the injected `RedisFactory` to borrow
native clients. Invalid domain values raise `InvalidRedisConfigurationException` before pool construction.
Config retains even a raw invalid option, so the loader validates every request. Readonly properties cannot be
changed after construction; changed settings require a new option and a new owning configuration lifecycle.

## Explicit pool assembly

`RedisPool::get($option, database: 2)` accepts the same concrete `RedisConfig` directly. It validates the item and
passes its unchanged `poolOption` to Pool. No timers are started automatically.

`RedisConnectionFactory` may be created before its owning `RedisConfig`. `RedisPool` binds the completed option
to that factory before constructing the pool, without opening a connection or replacing the factory. A factory
can bind only one option; repeated binding to that same object is allowed. When assembling a generic Pool directly,
call `$factory->bind($option)` first, or construct `new RedisConnectionFactory($option)` for a standalone factory.
Custom factories must create fresh `RedisConnection` wrappers using the same option.

One `PoolOption` identifies one pool within its owning `RedisPool`. Database sessions share that pool's capacity,
while each database has its own native session within a coroutine. Repeated access to the same option/database
reuses the session. Distinct options need their own `PoolOption`; sharing it between different option objects is
rejected. Database selection occurs before exposing a new session, and failed selection discards the acquired
connection. Once exposed, session state remains caller-owned.

## Native connection factory

`Factory\RedisFactory` has one operation:

```php
public function get(?string $name = null, ?int $database = null): \Redis;
```

It obtains an exclusive native connection from `Pool\RedisPool`. It does not construct proxy classes, dispatch Redis
commands, or manage shutdown. Receive the factory through typed constructor injection:

```php
use SuperKernel\Redis\Factory\RedisFactory;

final readonly class NativeCounter
{
    public function __construct(private RedisFactory $redisFactory)
    {
    }

    public function increment(string $key): mixed
    {
        return $this->redisFactory->get()->incr($key);
    }
}
```

`get()` and `get(null)` select the Config-owned default. Pass a configuration name to select its endpoint,
credentials, timeouts, database, and pool limits. The optional database argument overrides only the initial
database without modifying the retained configuration item:

```php
$default = $redisFactory->get();
$cache = $redisFactory->get('cache');
$queue = $redisFactory->get('cache', database: 2);

$default->set('key', 'value');
$cache->get('key');
$queue->rPush('messages', 'payload');
```

Run acquisition and commands inside an active Swoole coroutine. For one `RedisPool`, the same retained configuration
item and initial database share one native connection within that coroutine. A name and its default alias use that
same connection. Distinct options have independent pools; database overrides of one option share its pool capacity.
If two item methods intentionally return the same option, their pool is shared. Config retains option identity;
connections read the original readonly properties.

Factory results are actual `\Redis` objects. They support native signatures, including cursor references:

```php
$redis = $redisFactory->get();
$iterator = null;
do {
    $keys = $redis->scan($iterator, 'cache:*');
} while (0 !== $iterator);
```

All use must finish within the owning coroutine. Native results do not intercept command failures; if the caller
wants to retire a failed connection immediately, use the injected `RedisPool::discard($redis)`. Coroutine exit
retires every held native connection even when the caller does not explicitly discard it.

## Pooled proxy

`Proxy\Redis` is final and declares only `__call()` and `__callStatic()`. Its `@mixin \Redis` annotation provides
native API discovery. It does not extend native `\Redis`, so commands reach the magic methods instead of bypassing
the pool. There are no constructors, explicit command wrappers, or helper methods on the proxy.
`RedisFactoryProvider` initializes the proxy binding without acquiring a connection when DI resolves the factory.
Resolve that factory before using proxies; constructing or injecting a proxy alone does not initialize the binding.

Instance calls go through `__call()` to `__callStatic()`. Static dispatch obtains the native client through
`RedisFactory::get()` and invokes the requested native method:

```php
use SuperKernel\Redis\Proxy\Redis;

Redis::set('key', 'value');
$value = Redis::get('key');
```

Receive `Redis` through typed DI for instance calls. Both `Redis::get('key')` and `$redisProxy->get('key')`
use the default configuration item and its configured initial database, sharing the current coroutine's native
session. Configuration and pool lifecycle stay outside the proxy.

Use the injected factory to select a named item or override its initial database:

```php
$cache = $redisFactory->get('cache', database: 1);
$value = $cache->get('key');
```

Positional and named arguments, native return values, `false` replies, and last-error state are preserved. Native
fluent methods return the actual connection:

```php
$result = Redis::multi()->set('first', 'one')->set('second', 'two')->exec();
```

A proxy-dispatched call that throws retires that session and propagates its original throwable without replay.
Further acquisition of the same configuration/database in that coroutine fails. Calls made directly on native
fluent results are ordinary phpredis calls; the proxy cannot intercept their exceptions.

PHP magic dispatch cannot update the caller's by-reference cursor. Use the native factory result for `scan`,
`hScan`, `sScan`, and `zScan`; the proxy intentionally provides no explicit wrappers for those methods.

`RedisFactoryProvider` binds one default factory/pool pair per process or thread. Resolving a replacement provider
changes subsequent proxy calls, including calls through existing proxy objects. Already acquired native sessions
remain owned by their original pool.

## Native session lifetime

Native sessions retain a pool slot until coroutine exit, even after the last successful command. Long-lived
coroutines can exhaust the configured capacity. Transactions, pipelines, selected databases, and native options
persist for the session. Every native session is discarded at coroutine exit so this state cannot pass to the next
borrower. Never retain a native object after that point or pass it to another coroutine.

Connections initially use the configured database, disabled automatic command retries, and no serializer. Native
calls may change those settings for the current session. The component does not change global Swoole runtime hooks;
enable the appropriate socket hooks in the application when network I/O should yield.

## Lifecycle and boundaries

With `CoroutineCapacity`, load options and create resource-owning pools after process forks, separately in each
native thread. The retained option contains an owner-bound capacity coordinator. Never transfer initialized
coordinators, pools, factories, or sockets between processes or threads.

Inject `Pool\RedisPool` for native lifecycle management. Its `close()` permanently closes all configured native pools
and rejects subsequent factory acquisition and proxy calls. Held native clients may finish inside their owning
coroutine; coroutine exit retires them. Close the pool in its owning active coroutine before the scheduler terminates.
`RedisFactory` has no shutdown method; a proxy's `close()` forwards to the native connection.

`RedisPool` is registered directly with `#[Provider(RedisPool::class)]` and constructed by DI.
Explicit annotation lists include `RedisPool`; consumers inject that service identifier. Construction remains lazy with respect to connection I/O, and `RedisFactoryProvider` receives
the shared pool when it initializes the default proxy binding.

Deferred cleanup failures are retained by `RedisPool` and reported once by the next acquisition or `close()`.
Call `close()` during application shutdown even if no later command will expose a pending cleanup failure.
Failed physical cleanup keeps its pool capacity occupied. Repeated `close()` calls retry cleanup across all owned
pools, including failures from borrowers retired after the initial shutdown. Successfully closed connections stay closed.
The application owns shutdown scheduling.

`RedisFactory::get()` accepts an optional item name.
Pass `null` for the Config-owned default. The factory still has only its constructor and `get()` operation;
shutdown belongs to `RedisPool`. Both magic proxy forms use the pair initialized by `RedisFactoryProvider`.
Select named items and database overrides through the factory rather than proxy subclasses.

Each configuration targets a single Redis endpoint. Cluster, Sentinel discovery, PSR cache adapters,
automatic failover, and cross-process socket sharing are outside this component's current implementation.

## Native command and option contracts

Inject `RedisFactory` for a native Redis connection, or use an initialized `Proxy\Redis`. Native replies, errors,
transactions, and cursor-reference operations remain native; reference-parameter commands use the factory result.
Connections live until coroutine exit. `RedisPool` owns shutdown, while native `close()` closes only that connection.
The session discards its acquired connection wrapper once through the owning Pool.

Config discovers methods returning `Config\RedisConfig`, whose public properties include a concrete `PoolOption`.
Inject the option loader or factory for access; values are retained by Config without separate DI item bindings.
Database overrides share the option's pool capacity, so size it for all simultaneously held sessions.
Static and instance `Proxy\Redis` calls use the default session; named items and database overrides use
`RedisFactory::get($name, database: $database)`.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```

Live integration tests connect to a disposable Redis server on `127.0.0.1` at the port named by `REDIS_TEST_PORT`;
without it they are skipped.
