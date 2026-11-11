<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Redis

`super-kernel/redis` 为 SuperKernel 协程提供连接到单台 Redis 服务器的池化 phpredis 连接；这些连接配置为命名条目，以原生 `\Redis` 对象或通过静态代理使用。

## 安装

```bash
composer require super-kernel/redis
```

本包需要 `>=6.3.0 <6.4` 的 `redis` 扩展，以及通过 `super-kernel/pool` 引入的 `>=6.2.3 <6.3` 的 Swoole 扩展；连接是在协程中借用的。它使用 `super-kernel/pool` 进行池化，使用 `super-kernel/config` 查找配置，并需要 SKERNEL 构建项目。

## 提供的能力

- 声明为 `RedisConfig` 条目的命名连接设置，其中一个为默认条目。
- `RedisFactory::get($name, $database)`：返回当前协程的原生 `\Redis` 连接。
- 每个配置条目一个连接池，使用其 `PoolOption` 的上限和策略。
- 每个协程针对每个条目和数据库各一个连接，在协程结束时归还给连接池。
- `Redis` 代理：把静态调用和实例调用转发给默认连接。
- 在打开任何连接之前校验主机、端口、数据库、凭据、超时和连接池上限。

它不提供：

- Redis Cluster、Sentinel、TLS 或持久连接；
- 自动故障转移、重新连接或重复执行命令；
- PSR-6 或 PSR-16 缓存适配器；
- 值的序列化，连接一开始禁用序列化器；
- 在协程、进程或线程之间共享连接。

## 快速开始

在由 `super-kernel/skeleton` 创建、安装了 `super-kernel/redis`、并且 Redis 监听 `127.0.0.1:6379` 的应用中，声明连接。将它保存为 `src/Config/RedisConfigurations.php`：

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

在协程中使用连接。将脚本保存为 `bin/greeting.php`：

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

构建项目并运行脚本：

```bash
skernel build
php bin/greeting.php
```

它会打印 `Hello from Redis`。

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Attribute\Configuration` | 注解 | 声明 `RedisConfig` 配置源类，并标记每个返回条目的方法，用 `default: true` 标记默认条目。 |
| `SuperKernel\Contract\MultipleConfigInterface` | 接口 | 由其方法返回命名条目的配置源类实现。 |
| `SuperKernel\Redis\Config\RedisConfig` | 类 | 一个连接的连接池选项、主机、端口、数据库、凭据和超时。 |
| `SuperKernel\Redis\Factory\RedisConnectionFactory` | 类 | 放入每个条目的 `PoolOption` 中的连接工厂。 |
| `SuperKernel\Redis\Factory\RedisFactory` | 类 | `get($name, $database)` 返回协程的原生 `\Redis` 连接。 |
| `SuperKernel\Redis\Proxy\Redis` | 类 | 把静态调用和实例调用转发给默认连接。 |
| `SuperKernel\Redis\Pool\RedisPool` | 类 | 拥有各个连接池；`discard()` 废弃一个连接，`close()` 关闭所有连接池。 |
| `SuperKernel\Redis\Config\RedisOptionLoader` | 类 | `get($name)` 返回经过校验的配置条目。 |

## 需要了解的行为

- 只能在协程中借用连接；在其他地方，`get()` 会抛出 `SuperKernel\Pool\Exception\PoolException`，消息为 `Redis connection acquisition requires an active Swoole coroutine.` 在第一次 `get()` 之前不会打开任何连接。
- 未知名称会抛出 `SuperKernel\Config\Exception\ConfigurationNotFoundException`，消息为 `No Redis configuration item is registered for this name.`；有多个条目但没有默认条目时，只有不带名称的访问会失败。
- 无效的设置会在创建连接池之前抛出 `SuperKernel\Redis\Exception\InvalidRedisConfigurationException`，例如 `The Redis TCP port must be between 1 and 65535.`
- 连接在其协程结束之前一直占用连接池的槽位，因此长时间运行的协程会一直持有它；事务、管道、所选数据库和选项在此之前都保留在该连接上。
- 抛出异常的代理调用会废弃其连接；之后在该协程中再使用同一条目和数据库会抛出 `The native Redis session has already been retired.`
- 只有在容器创建了 `RedisFactory` 之后代理才能工作；在此之前它会抛出 `The Redis proxy requires initialization through RedisFactoryProvider.` 调用 `RedisPool::close()` 之后，每次借用都会抛出 `The Redis pool is closed.`
- 配置条目从构建元数据中发现：添加、删除或修改它们之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。

## 文档

开发者文档介绍了配置条目、工厂与会话、代理、跨数据库的池化、关闭与清理、错误消息以及不要这样做的事项：[SuperKernel Redis](https://github.com/super-kernel/.github/blob/main/docs/components/redis.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
