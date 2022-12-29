<?php

declare(strict_types=1);

namespace Linio\Doctrine\Provider;

use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\Common\EventManager;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pimple\Container;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class OrmServiceProviderTest extends TestCase
{
    /**
     * @return array{Container, Connection, EventManager}
     */
    protected function createMockDefaultAppAndDeps(): array
    {
        $container = new Container();

        $eventManager = $this->createMock('Doctrine\Common\EventManager');
        $connection = $this
            ->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $connection
            ->expects($this->any())
            ->method('getEventManager')
            ->will($this->returnValue($eventManager));

        $container['dbs'] = new Container([
            'default' => $connection,
        ]);

        $container['dbs.event_manager'] = new Container([
            'default' => $eventManager,
        ]);

        return [$container, $connection, $eventManager];
    }

    protected function createMockDefaultApp(): Container
    {
        [$container, $connection, $eventManager] = $this->createMockDefaultAppAndDeps();

        return $container;
    }

    /**
     * Test registration (test expected class for default implementations).
     */
    public function testRegisterDefaultImplementations(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        /** @var EntityManager[] $entityManagers */
        $entityManagers = $container['orm.ems'];

        $this->assertEquals($container['orm.em'], $entityManagers['default']);

        /** @var Configuration $ormEmConfig */
        $ormEmConfig = $container['orm.em.config'];

        /** @var DoctrineProvider $queryCacheImpl */
        $queryCacheImpl = $ormEmConfig->getQueryCacheImpl();
        /** @var DoctrineProvider $resultCacheImpl */
        $resultCacheImpl = $ormEmConfig->getResultCacheImpl();
        /** @var DoctrineProvider $metadataCacheImpl */
        $metadataCacheImpl = $ormEmConfig->getMetadataCacheImpl();
        /** @var DoctrineProvider $hydrationCacheImpl */
        $hydrationCacheImpl = $ormEmConfig->getHydrationCacheImpl();

        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $queryCacheImpl->getPool());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $resultCacheImpl->getPool());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $metadataCacheImpl->getPool());
        $this->assertInstanceOf('Symfony\Component\Cache\Adapter\ArrayAdapter', $hydrationCacheImpl->getPool());
        $this->assertInstanceOf('Doctrine\Persistence\Mapping\Driver\MappingDriverChain', $ormEmConfig->getMetadataDriverImpl());
    }

    /**
     * Test registration (test equality for defined implementations).
     */
    public function testRegisterDefinedImplementations(): void
    {
        $container = $this->createMockDefaultApp();

        $queryCache = DoctrineProvider::wrap(new ArrayAdapter());
        $resultCache = DoctrineProvider::wrap(new ArrayAdapter());
        $metadataCache = DoctrineProvider::wrap(new ArrayAdapter());

        $mappingDriverChain = $this->createMock('Doctrine\Persistence\Mapping\Driver\MappingDriverChain');

        $container['orm.cache.instances.default.query'] = $queryCache;
        $container['orm.cache.instances.default.result'] = $resultCache;
        $container['orm.cache.instances.default.metadata'] = $metadataCache;

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new OrmServiceProvider());

        /** @var EntityManager[] $entityManagers */
        $entityManagers = $container['orm.ems'];

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $container['orm.em.config'];

        $this->assertEquals($container['orm.em'], $entityManagers['default']);
        $this->assertEquals($queryCache, $entityManagerConfig->getQueryCacheImpl());
        $this->assertEquals($resultCache, $entityManagerConfig->getResultCacheImpl());
        $this->assertEquals($metadataCache, $entityManagerConfig->getMetadataCacheImpl());
        $this->assertEquals($mappingDriverChain, $entityManagerConfig->getMetadataDriverImpl());
    }

    /**
     * Test proxy configuration (defaults).
     */
    public function testProxyConfigurationDefaults(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $container['orm.em.config'];

        /** @var string $proxyDir */
        $proxyDir = $entityManagerConfig->getProxyDir();

        $this->assertStringContainsString('/../../../../../../../cache/doctrine/proxies', $proxyDir);
        $this->assertEquals('DoctrineProxy', $entityManagerConfig->getProxyNamespace());
        $this->assertEquals(AbstractProxyFactory::AUTOGENERATE_ALWAYS, $entityManagerConfig->getAutoGenerateProxyClasses());
    }

    /**
     * Test proxy configuration (defined).
     */
    public function testProxyConfigurationDefined(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        $entityRepositoryClassName = get_class($this->createMock('Doctrine\Persistence\ObjectRepository'));
        $metadataFactoryName = get_class($this->createMock('Doctrine\Persistence\Mapping\ClassMetadataFactory'));

        $entityListenerResolver = $this->createMock('Doctrine\ORM\Mapping\EntityListenerResolver');
        $repositoryFactory = $this->createMock('Doctrine\ORM\Repository\RepositoryFactory');

        $container['orm.proxies_dir'] = '/path/to/proxies';
        $container['orm.proxies_namespace'] = 'TestDoctrineOrmProxiesNamespace';
        $container['orm.auto_generate_proxies'] = false;
        $container['orm.class_metadata_factory_name'] = $metadataFactoryName;
        $container['orm.default_repository_class'] = $entityRepositoryClassName;
        $container['orm.entity_listener_resolver'] = $entityListenerResolver;
        $container['orm.repository_factory'] = $repositoryFactory;
        $container['orm.custom.hydration_modes'] = ['mymode' => 'Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator'];

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $container['orm.em.config'];

        $this->assertEquals('/path/to/proxies', $entityManagerConfig->getProxyDir());
        $this->assertEquals('TestDoctrineOrmProxiesNamespace', $entityManagerConfig->getProxyNamespace());
        $this->assertEquals(AbstractProxyFactory::AUTOGENERATE_NEVER, $entityManagerConfig->getAutoGenerateProxyClasses());
        $this->assertEquals($metadataFactoryName, $entityManagerConfig->getClassMetadataFactoryName());
        $this->assertEquals($entityRepositoryClassName, $entityManagerConfig->getDefaultRepositoryClassName());
        $this->assertEquals($entityListenerResolver, $entityManagerConfig->getEntityListenerResolver());
        $this->assertEquals($repositoryFactory, $entityManagerConfig->getRepositoryFactory());
        $this->assertEquals('Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator', $entityManagerConfig->getCustomHydrationMode('mymode'));
    }

    /**
     * Test Driver Chain locator.
     */
    public function testMappingDriverChainLocator(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        /** @var callable $mappingDriverChainLocator */
        $mappingDriverChainLocator = $container['orm.mapping_driver_chain.locator'];

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $container['orm.em.config'];

        $default = $mappingDriverChainLocator();

        $this->assertEquals($default, $mappingDriverChainLocator('default'));
        $this->assertEquals($default, $entityManagerConfig->getMetadataDriverImpl());
    }

    /**
     * Test adding a mapping driver (use default entity manager).
     */
    public function testAddMappingDriverDefault(): void
    {
        $container = $this->createMockDefaultApp();

        $mappingDriver = $this->createMock('Doctrine\Persistence\Mapping\Driver\MappingDriver');

        $mappingDriverChain = $this->createMock('Doctrine\Persistence\Mapping\Driver\MappingDriverChain');
        $mappingDriverChain
            ->expects($this->once())
            ->method('addDriver')
            ->with($mappingDriver, 'Test\Namespace');

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new OrmServiceProvider());

        /** @var callable $addMappingDriver */
        $addMappingDriver = $container['orm.add_mapping_driver'];

        $addMappingDriver($mappingDriver, 'Test\Namespace');
    }

    /**
     * Test adding a mapping driver (specify default entity manager by name).
     */
    public function testAddMappingDriverNamedEntityManager(): void
    {
        $container = $this->createMockDefaultApp();

        $mappingDriver = $this->createMock('Doctrine\Persistence\Mapping\Driver\MappingDriver');

        $mappingDriverChain = $this->createMock('Doctrine\Persistence\Mapping\Driver\MappingDriverChain');
        $mappingDriverChain
            ->expects($this->once())
            ->method('addDriver')
            ->with($mappingDriver, 'Test\Namespace');

        $container['orm.mapping_driver_chain.instances.default'] = $mappingDriverChain;

        $container->register(new OrmServiceProvider());

        /** @var callable $addMappingDriver */
        $addMappingDriver = $container['orm.add_mapping_driver'];

        $addMappingDriver($mappingDriver, 'Test\Namespace');
    }

    /**
     * Test specifying an invalid cache type (just named).
     */
    public function testInvalidCacheTypeNamed(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        $container['orm.em.options'] = [
            'query_cache' => 'INVALID',
        ];

        try {
            $entityManager = $container['orm.em'];

            $this->fail('Expected invalid query cache driver exception');
        } catch (RuntimeException $e) {
            $this->assertEquals("Unsupported cache type 'INVALID' specified", $e->getMessage());
        }
    }

    /**
     * Test specifying an invalid cache type (driver as option).
     */
    public function testInvalidCacheTypeDriverAsOption(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        $container['orm.em.options'] = [
            'query_cache' => [
                'driver' => 'INVALID',
            ],
        ];

        try {
            $entityManager = $container['orm.em'];

            $this->fail('Expected invalid query cache driver exception');
        } catch (RuntimeException $e) {
            $this->assertEquals("Unsupported cache type 'INVALID' specified", $e->getMessage());
        }
    }

    /**
     * Test orm.em_name_from_param_key ().
     */
    public function testNameFromParamKey(): void
    {
        $container = $this->createMockDefaultApp();

        $container['my.baz'] = 'baz';

        $container->register(new OrmServiceProvider());

        $container['orm.ems.default'] = 'foo';

        /** @var callable $nameFomParamKey */
        $nameFomParamKey = $container['orm.em_name_from_param_key'];

        $this->assertEquals('foo', $container['orm.ems.default']);
        $this->assertEquals('foo', $nameFomParamKey('my.bar'));
        $this->assertEquals('baz', $nameFomParamKey('my.baz'));
    }

    /**
     * Test specifying an invalid mapping configuration (not an array of arrays).
     */
    public function testInvalidMappingAsOption(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        $container['orm.em.options'] = [
            'mappings' => [
                'type' => 'annotation',
                'namespace' => 'Foo\Entities',
                'path' => __DIR__ . '/src/Foo/Entities',
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The \'orm.em.options\' option \'mappings\' should be an array of arrays.');

        $entityManagersConfig = $container['orm.ems.config'];
    }

    /**
     * Test if namespace alias can be set through the mapping options.
     */
    public function testMappingAlias(): void
    {
        $container = $this->createMockDefaultApp();

        $container->register(new OrmServiceProvider());

        $alias = 'Foo';
        $namespace = 'Foo\Entities';

        $container['orm.em.options'] = [
            'mappings' => [
                [
                    'type' => 'annotation',
                    'namespace' => $namespace,
                    'path' => __DIR__ . '/src/Foo/Entities',
                    'alias' => $alias,
                ],
            ],
        ];

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $container['orm.em.config'];

        $this->assertEquals($namespace, $entityManagerConfig->getEntityNameSpace($alias));
    }

    public function testStrategy(): void
    {
        $app = $this->createMockDefaultApp();

        $doctrineOrmServiceProvider = new OrmServiceProvider();
        $doctrineOrmServiceProvider->register($app);

        $namingStrategy = $this->createMock('Doctrine\ORM\Mapping\DefaultNamingStrategy');
        $quoteStrategy = $this->createMock('Doctrine\ORM\Mapping\DefaultQuoteStrategy');

        $app['orm.strategy.naming'] = $namingStrategy;
        $app['orm.strategy.quote'] = $quoteStrategy;

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $app['orm.em.config'];

        $this->assertEquals($namingStrategy, $entityManagerConfig->getNamingStrategy());
        $this->assertEquals($quoteStrategy, $entityManagerConfig->getQuoteStrategy());
    }

    public function testCustomFunctions(): void
    {
        $app = $this->createMockDefaultApp();

        $doctrineOrmServiceProvider = new OrmServiceProvider();
        $doctrineOrmServiceProvider->register($app);

        $numericFunction = $this->createMock('Doctrine\ORM\Query\AST\Functions\FunctionNode');
        $stringFunction = $this->createMock('Doctrine\ORM\Query\AST\Functions\FunctionNode');
        $datetimeFunction = $this->createMock('Doctrine\ORM\Query\AST\Functions\FunctionNode');

        $app['orm.custom.functions.string'] = ['mystring' => $numericFunction];
        $app['orm.custom.functions.numeric'] = ['mynumeric' => $stringFunction];
        $app['orm.custom.functions.datetime'] = ['mydatetime' => $datetimeFunction];

        /** @var Configuration $entityManagerConfig */
        $entityManagerConfig = $app['orm.em.config'];

        $this->assertEquals($numericFunction, $entityManagerConfig->getCustomStringFunction('mystring'));
        $this->assertEquals($numericFunction, $entityManagerConfig->getCustomNumericFunction('mynumeric'));
        $this->assertEquals($numericFunction, $entityManagerConfig->getCustomDatetimeFunction('mydatetime'));
    }
}
