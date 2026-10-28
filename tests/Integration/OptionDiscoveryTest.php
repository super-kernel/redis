<?php

declare(strict_types=1);

namespace SuperKernel\Redis\Tests\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use SuperKernel;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Annotator\Model\PackageAnnotations;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Context\ApplicationContext;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Di\Container;
use SuperKernel\Redis\Config\RedisConfig;
use SuperKernel\Redis\Config\RedisOptionLoader;
use SuperKernel\Redis\Exception\InvalidRedisConfigurationException;
use SuperKernel\Redis\Factory\RedisFactory;
use SuperKernel\Redis\Pool\RedisPool;
use SuperKernel\Redis\Proxy\Redis;
use SuperKernel\Redis\Tests\Fixture\RedisConfigurationItems;
use SuperKernel\Redis\Tests\Fixture\RedisFactoryConsumer;
use SuperKernel\Redis\Tests\Fixture\SingleRedisConfiguration;
use Throwable;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OptionDiscoveryTest extends TestCase
{
    /** @throws Throwable When the registry or factory cannot be resolved */
    public function testMultipleOptionsAreDiscoveredAndRetainedByConfig(): void
    {
        $container = $this->container(source: RedisConfigurationItems::class);
        $factory = $container->get(RedisFactory::class);
        self::assertInstanceOf(RedisFactory::class, $factory);
        $source = $container->get(RedisConfigurationItems::class);
        self::assertInstanceOf(RedisConfigurationItems::class, $source);
        self::assertSame([], $source->calls);

        try {
            $factory->get('missing');
            self::fail('Unknown names must fail without resolving the default item.');
        } catch (ConfigurationNotFoundException) {
            self::assertSame([], $source->calls);
        }

        $loader = $container->get(RedisOptionLoader::class);
        self::assertInstanceOf(RedisOptionLoader::class, $loader);
        $default = $loader->get();
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);
        self::assertSame($default, $registry->get(RedisConfig::class));
        self::assertSame($default, $loader->get('primary'));
        self::assertNotSame($default, $loader->get('cache'));
        self::assertSame([
            'primary' => 1,
            'cache'   => 1,
        ], $source->calls);
    }

    /** @throws Throwable When named item validation fails */
    public function testNamedSettingsAreValidatedBeforeBorrowing(): void
    {
        $container = $this->container(source: RedisConfigurationItems::class);
        $source = $container->get(RedisConfigurationItems::class);
        self::assertInstanceOf(RedisConfigurationItems::class, $source);
        $source->cacheHost = '';
        $factory = $container->get(RedisFactory::class);
        self::assertInstanceOf(RedisFactory::class, $factory);
        $this->expectException(InvalidRedisConfigurationException::class);

        $factory->get('cache');
    }

    /** @throws Throwable When factory services cannot be injected */
    public function testServicesAreInjectedWithoutLoadingOptionsOrOpeningAConnection(): void
    {
        $container = $this->container();
        $source = $container->get(SingleRedisConfiguration::class);
        self::assertInstanceOf(SingleRedisConfiguration::class, $source);
        $consumer = $container->get(RedisFactoryConsumer::class);
        self::assertInstanceOf(RedisFactoryConsumer::class, $consumer);

        self::assertSame($consumer->factory, $container->get(RedisFactory::class));
        self::assertSame($consumer->proxy, $container->get(Redis::class));
        self::assertSame($consumer->pool, $container->get(RedisPool::class));
        self::assertSame(0, $source->calls);

        $consumer->pool->close();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('The Redis pool is closed.');

        $consumer->factory->get();
    }

    /** @throws Throwable When the option source or registry fails */
    public function testASoleOptionMethodIsTheImplicitDefaultAndRetainsItsIdentity(): void
    {
        $container = $this->container();
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);
        $source = $container->get(SingleRedisConfiguration::class);
        self::assertInstanceOf(SingleRedisConfiguration::class, $source);
        self::assertTrue($registry->has(RedisConfig::class));
        self::assertSame(0, $source->calls);
        $option = $registry->get(RedisConfig::class);
        self::assertInstanceOf(RedisConfig::class, $option);
        self::assertSame($option, $registry->get(RedisConfig::class, 'connection'));
        self::assertSame($source->host, $option->host);
        self::assertSame(1, $source->calls);
        self::assertNull($registry->get(RedisConfig::class, 'missing'));
    }

    /** @throws Throwable When the configuration or container graph fails */
    public function testInvalidRetainedOptionsAreValidatedOnEveryRequest(): void
    {
        $container = $this->container();
        $source = $container->get(SingleRedisConfiguration::class);
        self::assertInstanceOf(SingleRedisConfiguration::class, $source);
        $source->host = '';
        $loader = $container->get(RedisOptionLoader::class);
        self::assertInstanceOf(RedisOptionLoader::class, $loader);

        for ($attempt = 0; 2 > $attempt; ++$attempt) {
            try {
                $loader->get();
                self::fail('Invalid retained options must fail validation on every request.');
            } catch (InvalidRedisConfigurationException) {
                self::assertSame(1, $source->calls);
            }
        }
    }

    /** @throws Throwable When the configuration or container graph fails */
    public function testAnInstalledRedisFactoryDoesNotHideAMissingConfigurationDeclaration(): void
    {
        $container = $this->container(false);
        self::assertTrue($container->has(RedisFactory::class));
        $factory = $container->get(RedisFactory::class);
        self::assertInstanceOf(RedisFactory::class, $factory);
        $registry = $container->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $registry);
        self::assertFalse($registry->has(RedisConfig::class));
        $this->expectException(ConfigurationNotFoundException::class);

        $factory->get();
    }

    /**
     * @param class-string|null $source
     *
     * @throws ReflectionException When a production or fixture declaration cannot be reflected
     */
    private function container(bool $declareSource = true, ?string $source = null): Container
    {
        $classes = [
            SuperKernel\Di\Definer\ProviderDefiner::class,
            SuperKernel\Di\Definer\FactoryDefiner::class,
            SuperKernel\Di\Definer\ClassDefiner::class,
            SuperKernel\Di\Resolver\FactoryResolver::class,
            SuperKernel\Di\Resolver\ProviderResolver::class,
            SuperKernel\Di\Resolver\ClassResolver::class,
            SuperKernel\Di\Resolver\PropertyResolver::class,
            SuperKernel\Di\Resolver\MethodResolver::class,
            SuperKernel\Di\PropertyHandler\AutowiredPropertyHandler::class,
            SuperKernel\Reflector\Provider\ReflectorProvider::class,
            SuperKernel\Config\Provider\ConfigProvider::class,
            SuperKernel\Redis\Provider\RedisFactoryProvider::class,
            SuperKernel\Redis\Pool\RedisPool::class,
        ];
        if ($declareSource) {
            $classes[] = $source ?? SingleRedisConfiguration::class;
        }
        $metadata = [];
        foreach ($classes as $class) {
            foreach (new ReflectionClass($class)->getAttributes() as $attribute) {
                $metadata[$class][] = new AttributeMetadata($attribute->newInstance());
            }
        }
        $container = new Container(new AnnotationCollector([
            new PackageAnnotations('super-kernel/redis-test', null, $metadata, [], []),
        ]));
        ApplicationContext::setContainer($container);

        return $container;
    }
}
