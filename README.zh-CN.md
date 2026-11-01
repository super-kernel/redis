<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Redis

`super-kernel/redis` 把单服务器的 phpredis 连接与独占的协程会话以及 SuperKernel Pool 组件集成在一起。它提供原生的 `\Redis` 客户端，以及透明的实例/静态代理。

## 由 Config 发现的选项

`SuperKernel\Redis\Config\RedisConfig` 是一个 `final readonly` 类，其所有属性都是公开的：`poolOption`、`host`、`port`、`database`、`username`、`password`、`connectTimeout` 和 `readTimeout`。以属性方式读取设置，例如 `$option->host` 和 `$option->poolOption`。必需的 `PoolOption` 包含要使用的确切的连接工厂、容量协调器、上限、健康检查模式和频率策略。Redis 不会复制它，也不会另建一套连接池设置。

Config 负责发现、命名、选择默认值、延迟创建并保留一个或多个 `RedisConfig` 条目；Redis 选项不会被单独注册为 DI 条目。`RedisOptionLoader` 从 `ConfigInterface` 取得每个被请求的条目，校验其连接和连接池设置，然后返回同一个对象，不打开任何连接。

用 `#[Configuration(RedisConfig::class)]` 和 `MultipleConfigInterface` 声明一个应用来源。每个带有 `#[Configuration]` 的公开无参方法返回一个选项。这种来源形式既支持单个选项，也支持多个具名选项：

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

方法名就是区分大小写的条目名称。只有一个被标记方法的来源不需要默认标志。有多个条目时，用 `default: true` 选择不带名称时的默认值。没有默认值时，具名查询仍然可用，只有不带名称的访问会失败。未知名称从不回退到其他条目。

注入了 `ConfigInterface` 的使用方可以直接查看被保留的选项：

```php
$primary = $config->get(RedisConfig::class);
$same = $config->get(RedisConfig::class, 'primary');
$cache = $config->get(RedisConfig::class, 'cache');
```

需要经过校验的选项时，使用注入的 `RedisOptionLoader::get($name)`；需要借用原生客户端时，使用注入的 `RedisFactory`。无效的领域值会在构造连接池之前抛出 `InvalidRedisConfigurationException`。Config 连原始的无效选项也会保留，所以加载器会校验每一次请求。只读属性在构造后无法修改；要更改设置，需要新的选项和新的配置生命周期。

## 显式组装连接池

`RedisPool::get($option, database: 2)` 直接接受同一个具体的 `RedisConfig`。它校验该条目，并把未经修改的 `poolOption` 交给 Pool。不会自动启动任何定时器。

`RedisConnectionFactory` 可以在其所属的 `RedisConfig` 之前创建。`RedisPool` 在构造连接池之前，把完整的选项绑定到该工厂，不打开连接，也不替换工厂。一个工厂只能绑定一个选项；对同一个对象重复绑定是允许的。直接组装通用的 Pool 时，先调用 `$factory->bind($option)`，或者构造 `new RedisConnectionFactory($option)` 作为独立的工厂。自定义工厂必须用同一个选项创建新的 `RedisConnection` 包装器。

一个 `PoolOption` 在其所属的 `RedisPool` 中标识一个连接池。各数据库的会话共享这个连接池的容量，而每个数据库在一个协程内各有自己的原生会话。重复访问同一个选项/数据库会复用该会话。不同的选项需要各自的 `PoolOption`；在不同的选项对象之间共享它会被拒绝。数据库选择在暴露新会话之前完成，选择失败时会丢弃已取得的连接。会话暴露之后，其状态由调用方负责。

## 原生连接工厂

`Factory\RedisFactory` 只有一个操作：

```php
public function get(?string $name = null, ?int $database = null): \Redis;
```

它从 `Pool\RedisPool` 取得一个独占的原生连接。它不构造代理类、不分发 Redis 命令，也不管理关闭。通过类型化的构造函数注入接收这个工厂：

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

`get()` 和 `get(null)` 选择 Config 管理的默认值。传入配置名称即可选择其端点、凭据、超时、数据库和连接池上限。可选的数据库参数只覆盖初始数据库，不修改被保留的配置条目：

```php
$default = $redisFactory->get();
$cache = $redisFactory->get('cache');
$queue = $redisFactory->get('cache', database: 2);

$default->set('key', 'value');
$cache->get('key');
$queue->rPush('messages', 'payload');
```

请在活动的 Swoole 协程中取得连接并执行命令。对于同一个 `RedisPool`，同一个被保留的配置条目加上相同的初始数据库，在一个协程内共享一个原生连接。名称及其默认别名使用同一个连接。不同的选项各有独立的连接池；同一个选项的数据库覆盖共享其连接池容量，因此请按所有同时持有的会话来设置容量。如果两个条目方法有意返回同一个选项，它们的连接池也是共享的。Config 保留选项的身份；连接读取原始的只读属性。

工厂返回的是真正的 `\Redis` 对象，支持原生签名，包括游标引用：

```php
$redis = $redisFactory->get();
$iterator = null;
do {
    $keys = $redis->scan($iterator, 'cache:*');
} while (0 !== $iterator);
```

所有使用都必须在所属的协程内完成。原生结果不会拦截命令失败；如果调用方希望立即淘汰一个失败的连接，请使用注入的 `RedisPool::discard($redis)`。协程退出时，即使调用方没有显式丢弃，也会淘汰其持有的所有原生连接。

## 连接池代理

`Proxy\Redis` 把静态调用和实例调用转发给 `RedisFactory::get()` 为默认配置条目返回的原生客户端。它的 `@mixin \Redis` 注解提供原生 API 的提示；它不继承 `\Redis`，因此每条命令都经过连接池。代理的绑定在 DI 解析 `RedisFactory` 时初始化，不会取得连接。使用代理之前请先解析该工厂；仅构造或注入代理并不会初始化绑定。

```php
use SuperKernel\Redis\Proxy\Redis;

Redis::set('key', 'value');
$value = Redis::get('key');
```

需要实例调用时，通过类型化的 DI 接收 `Redis`。`Redis::get('key')` 和 `$redisProxy->get('key')` 都使用默认配置条目及其配置的初始数据库，并共享当前协程的原生会话。配置和连接池的生命周期都在代理之外。

需要选择具名条目或覆盖初始数据库时，使用注入的工厂：

```php
$cache = $redisFactory->get('cache', database: 1);
$value = $cache->get('key');
```

位置参数和命名参数、原生返回值、`false` 回复以及最近一次错误状态都会保留。原生的链式方法返回真正的连接：

```php
$result = Redis::multi()->set('first', 'one')->set('second', 'two')->exec();
```

通过代理分发的调用抛出异常时，会淘汰该会话，并原样传播原始异常，不会重放。此后在该协程中再次取得同一配置/数据库会失败。直接在原生链式结果上进行的调用是普通的 phpredis 调用，代理无法拦截其异常。

PHP 的魔术分发无法更新调用方的引用游标。对 `scan`、`hScan`、`sScan` 和 `zScan`，请使用工厂返回的原生结果；代理不为这些方法提供包装。

代理在每个进程或线程中绑定一对默认的工厂/连接池。解析一个替换的工厂提供者会改变之后的代理调用，包括通过已有代理对象的调用。已经取得的原生会话仍归其原来的连接池所有。

## 原生会话的生命周期

原生会话会一直占用一个连接池位置，直到协程退出，即使最后一条命令已经成功完成。长时间运行的协程可能耗尽配置的容量。事务、管道、所选数据库和原生选项会在会话期间一直保持。每个原生会话都会在协程退出时被丢弃，因此这些状态不会传给下一个借用者。在那之后切勿继续持有原生对象，也不要把它交给另一个协程。

连接初始使用配置的数据库、禁用自动命令重试，并且不使用序列化器。原生调用可以为当前会话修改这些设置。本组件不会修改全局的 Swoole 运行时 hook；如果希望网络 I/O 让出协程，请在应用中启用相应的 socket hook。

## 生命周期与边界

使用 `CoroutineCapacity` 时，请在进程 fork 之后加载选项并创建持有资源的连接池，在每个原生线程中分别进行。被保留的选项包含一个与所有者绑定的容量协调器。切勿在进程或线程之间传递已初始化的协调器、连接池、工厂或 socket。

需要管理原生连接的生命周期时，注入 `Pool\RedisPool`。它的 `close()` 会永久关闭所有已配置的原生连接池，并拒绝之后的工厂获取和代理调用。已持有的原生客户端可以在其所属协程内完成工作；协程退出时会淘汰它们。请在调度器结束之前，在所属的活动协程中关闭连接池。`RedisFactory` 没有关闭方法；代理的 `close()` 会转发给原生连接，只关闭那一个连接。构造 `RedisPool` 时不会进行任何连接 I/O。

延迟的清理失败由 `RedisPool` 保留，并在下一次获取或 `close()` 时报告一次。即使之后不会再执行任何命令来暴露待处理的清理失败，也请在应用关闭时调用 `close()`。物理清理失败时，其占用的连接池容量不会释放。重复调用 `close()` 会对所有拥有的连接池重试清理，包括在首次关闭之后被淘汰的借用者的失败。已成功关闭的连接保持关闭。关闭的时机由应用负责安排。

每个配置只针对一个 Redis 端点。不提供 Cluster、Sentinel 发现、PSR 缓存适配器、自动故障转移或跨进程共享 socket。
