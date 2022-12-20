<?php

declare(strict_types=1);

namespace Linio\Doctrine\Provider;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\Psr6\DoctrineProvider;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Cache\DefaultCacheFactory;
use Doctrine\ORM\Cache\Region\DefaultRegion;
use Doctrine\ORM\Cache\RegionsConfiguration;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\DefaultEntityListenerResolver;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedYamlDriver;
use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Mapping\Driver\YamlDriver;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\Driver\StaticPHPDriver;
use InvalidArgumentException;
use Memcached;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Redis;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

class OrmServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        foreach ($this->getOrmDefaults() as $key => $value) {
            if (!isset($container[$key])) {
                $container[$key] = $value;
            }
        }

        $container['orm.em.default_options'] = [
            'connection' => 'default',
            'mappings' => [],
            'resolve_target_entities' => [],
            'types' => [],
        ];

        $container['orm.ems.options.initializer'] = $container->protect(function () use ($container): void {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($container['orm.ems.options'])) {
                $container['orm.ems.options'] = ['default' => $container['orm.em.options'] ?? []];
            }

            $tmp = $container['orm.ems.options'];
            foreach ($tmp as $name => &$options) {
                $options = array_replace($container['orm.em.default_options'], $options);

                if (!isset($container['orm.ems.default'])) {
                    $container['orm.ems.default'] = $name;
                }
            }
            $container['orm.ems.options'] = $tmp;
        });

        $container['orm.em_name_from_param_key'] = $container->protect(function ($paramKey) use ($container) {
            $container['orm.ems.options.initializer']();

            if (isset($container[$paramKey])) {
                return $container[$paramKey];
            }

            return $container['orm.ems.default'];
        });

        $container['orm.ems'] = function ($container) {
            $container['orm.ems.options.initializer']();

            $ems = new Container();
            foreach ($container['orm.ems.options'] as $name => $options) {
                if ($container['orm.ems.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $container['orm.em.config'];
                } else {
                    $config = $container['orm.ems.config'][$name];
                }

                $ems[$name] = function ($ems) use ($container, $options, $config) {
                    $evm = $container['dbs.event_manager'][$options['connection']];
                    // @var $evm \Doctrine\Common\EventManager

                    if (isset($container['orm.ems.listener.interface_to_concrete_class'])) {
                        $evm->addEventSubscriber($container['orm.ems.listener.interface_to_concrete_class']);
                    }

                    return EntityManager::create(
                        $container['dbs'][$options['connection']],
                        $config,
                        $evm
                    );
                };
            }

            return $ems;
        };

        $container['orm.ems.config'] = function ($container) {
            $container['orm.ems.options.initializer']();

            $configs = new Container();
            foreach ($container['orm.ems.options'] as $name => $options) {
                $config = new Configuration();

                $container['orm.cache.configurer']($name, $config, $options);

                $config->setProxyDir($container['orm.proxies_dir']);
                $config->setProxyNamespace($container['orm.proxies_namespace']);
                $config->setAutoGenerateProxyClasses($container['orm.auto_generate_proxies']);

                $config->setCustomStringFunctions($container['orm.custom.functions.string']);
                $config->setCustomNumericFunctions($container['orm.custom.functions.numeric']);
                $config->setCustomDatetimeFunctions($container['orm.custom.functions.datetime']);
                $config->setCustomHydrationModes($container['orm.custom.hydration_modes']);

                $config->setClassMetadataFactoryName($container['orm.class_metadata_factory_name']);
                $config->setDefaultRepositoryClassName($container['orm.default_repository_class']);

                $config->setEntityListenerResolver($container['orm.entity_listener_resolver']);
                $config->setRepositoryFactory($container['orm.repository_factory']);

                $config->setNamingStrategy($container['orm.strategy.naming']);
                $config->setQuoteStrategy($container['orm.strategy.quote']);

                $chain = $container['orm.mapping_driver_chain.locator']($name);

                foreach ((array) $options['mappings'] as $entity) {
                    if (!is_array($entity)) {
                        throw new InvalidArgumentException("The 'orm.em.options' option 'mappings' should be an array of arrays.");
                    }

                    if (isset($entity['alias'])) {
                        $config->addEntityNamespace($entity['alias'], $entity['namespace']);
                    }

                    switch ($entity['type']) {
                        case 'annotation':
                            $useSimpleAnnotationReader =
                                $entity['use_simple_annotation_reader']
                                ?? true;
                            $driver = $config->newDefaultAnnotationDriver((array) $entity['path'], $useSimpleAnnotationReader);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'yml':
                            $driver = new YamlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'simple_yml':
                            $driver = new SimplifiedYamlDriver([$entity['path'] => $entity['namespace']]);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'xml':
                            $driver = new XmlDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'simple_xml':
                            $driver = new SimplifiedXmlDriver([$entity['path'] => $entity['namespace']]);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        case 'php':
                            $driver = new StaticPHPDriver($entity['path']);
                            $chain->addDriver($driver, $entity['namespace']);
                            break;
                        default:
                            throw new InvalidArgumentException(sprintf('"%s" is not a recognized driver', $entity['type']));
                            break;
                    }
                }
                $config->setMetadataDriverImpl($chain);

                foreach ((array) $options['types'] as $typeName => $typeClass) {
                    if (Type::hasType($typeName)) {
                        Type::overrideType($typeName, $typeClass);
                    } else {
                        Type::addType($typeName, $typeClass);
                    }
                }

                $configs[$name] = $config;
            }

            return $configs;
        };

        $container['orm.cache.configurer'] = $container->protect(function ($name, Configuration $config, $options) use ($container): void {
            if (isset($options['second_level'])) {
                $cacheRegion = new DefaultRegion($name, $container['orm.cache.locator']($name, 'second_level', $options));
                $regionsConfiguration = new RegionsConfiguration($options['second_level']['ttl'], $options['second_level']['lock_ttl']);
                $cacheFactory = new DefaultCacheFactory($regionsConfiguration, $container['orm.cache.locator']($name, 'second_level', $options));
                $config->setSecondLevelCacheEnabled();
                $config->getSecondLevelCacheConfiguration()->setCacheFactory($cacheFactory);
            }

            $config->setMetadataCacheImpl($container['orm.cache.locator']($name, 'metadata', $options));
            $config->setQueryCacheImpl($container['orm.cache.locator']($name, 'query', $options));
            $config->setResultCacheImpl($container['orm.cache.locator']($name, 'result', $options));
            $config->setHydrationCacheImpl($container['orm.cache.locator']($name, 'hydration', $options));
        });

        $container['orm.cache.locator'] = $container->protect(function ($name, $cacheName, $options) use ($container) {
            $cacheNameKey = $cacheName . '_cache';

            if (!isset($options[$cacheNameKey])) {
                $options[$cacheNameKey] = $container['orm.default_cache'];
            }

            if (isset($options[$cacheNameKey]) && !is_array($options[$cacheNameKey])) {
                $options[$cacheNameKey] = [
                    'driver' => $options[$cacheNameKey],
                ];
            }

            if (!isset($options[$cacheNameKey]['driver'])) {
                throw new RuntimeException("No driver specified for '$cacheName'");
            }

            $driver = $options[$cacheNameKey]['driver'];

            $cacheInstanceKey = 'orm.cache.instances.' . $name . '.' . $cacheName;
            if (isset($container[$cacheInstanceKey])) {
                return $container[$cacheInstanceKey];
            }

            $cache = $container['orm.cache.factory']($driver, $options[$cacheNameKey]);

            if (isset($options['cache_namespace']) && $cache instanceof CacheProvider) {
                $cache->setNamespace($options['cache_namespace']);
            }

            return $container[$cacheInstanceKey] = $cache;
        });

        $container['orm.cache.factory.backing_memcached'] = $container->protect(function () {
            return new Memcached();
        });

        $container['orm.cache.factory.memcached'] = $container->protect(function ($cacheOptions) use ($container) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new RuntimeException('Host and port options need to be specified for memcached cache');
            }

            /** @var Memcached $memcached */
            $memcached = $container['orm.cache.factory.backing_memcached']();
            $memcached->addServer($cacheOptions['host'], $cacheOptions['port']);

            return DoctrineProvider::wrap(new MemcachedAdapter($memcached));
        });

        $container['orm.cache.factory.backing_redis'] = $container->protect(function () {
            return new Redis();
        });

        $container['orm.cache.factory.redis'] = $container->protect(function ($cacheOptions) use ($container) {
            if (empty($cacheOptions['host']) || empty($cacheOptions['port'])) {
                throw new RuntimeException('Host and port options need to be specified for redis cache');
            }

            /** @var Redis $redis */
            $redis = $container['orm.cache.factory.backing_redis']();
            $redis->connect($cacheOptions['host'], $cacheOptions['port']);

            return DoctrineProvider::wrap(new RedisAdapter($redis));
        });

        $container['orm.cache.factory.array'] = $container->protect(function () {
            return DoctrineProvider::wrap(new ArrayAdapter());
        });

        $container['orm.cache.factory.apcu'] = $container->protect(function () {
            return DoctrineProvider::wrap(new ApcuAdapter());
        });

        $container['orm.cache.factory.filesystem'] = $container->protect(function ($cacheOptions) {
            if (empty($cacheOptions['path'])) {
                throw new RuntimeException('FilesystemCache path not defined');
            }

            return DoctrineProvider::wrap(new FilesystemAdapter('', 0, $cacheOptions['path']));
        });

        $container['orm.cache.factory'] = $container->protect(function ($driver, $cacheOptions) use ($container) {
            switch ($driver) {
                case 'apcu':
                    return $container['orm.cache.factory.apcu']();
                case 'array':
                    return $container['orm.cache.factory.array']();
                case 'filesystem':
                    return $container['orm.cache.factory.filesystem']($cacheOptions);
                case 'memcached':
                    return $container['orm.cache.factory.memcached']($cacheOptions);
                case 'redis':
                    return $container['orm.cache.factory.redis']($cacheOptions);
                default:
                    throw new RuntimeException("Unsupported cache type '$driver' specified");
            }
        });

        $container['orm.mapping_driver_chain.locator'] = $container->protect(function ($name = null) use ($container) {
            $container['orm.ems.options.initializer']();

            if ($name === null) {
                $name = $container['orm.ems.default'];
            }

            $cacheInstanceKey = 'orm.mapping_driver_chain.instances.' . $name;
            if (isset($container[$cacheInstanceKey])) {
                return $container[$cacheInstanceKey];
            }

            return $container[$cacheInstanceKey] = $container['orm.mapping_driver_chain.factory']($name);
        });

        $container['orm.mapping_driver_chain.factory'] = $container->protect(function ($name) {
            return new MappingDriverChain();
        });

        $container['orm.add_mapping_driver'] = $container->protect(function (MappingDriver $mappingDriver, $namespace, $name = null) use ($container): void {
            $container['orm.ems.options.initializer']();

            if ($name === null) {
                $name = $container['orm.ems.default'];
            }

            /** @var MappingDriverChain $driverChain */
            $driverChain = $container['orm.mapping_driver_chain.locator']($name);
            $driverChain->addDriver($mappingDriver, $namespace);
        });

        $container['orm.strategy.naming'] = function ($container) {
            return new DefaultNamingStrategy();
        };

        $container['orm.strategy.quote'] = function ($container) {
            return new DefaultQuoteStrategy();
        };

        $container['orm.entity_listener_resolver'] = function ($container) {
            return new DefaultEntityListenerResolver();
        };

        $container['orm.repository_factory'] = function ($container) {
            return new DefaultRepositoryFactory();
        };

        $container['orm.em'] = function ($container) {
            $ems = $container['orm.ems'];

            return $ems[$container['orm.ems.default']];
        };

        $container['orm.em.config'] = function ($container) {
            $configs = $container['orm.ems.config'];

            return $configs[$container['orm.ems.default']];
        };
    }

    /**
     * Get default ORM configuration settings.
     *
     * @return array
     */
    protected function getOrmDefaults()
    {
        return [
            'orm.proxies_dir' => __DIR__ . '/../../../../../../../../cache/doctrine/proxies',
            'orm.proxies_namespace' => 'DoctrineProxy',
            'orm.auto_generate_proxies' => true,
            'orm.default_cache' => 'array',
            'orm.custom.functions.string' => [],
            'orm.custom.functions.numeric' => [],
            'orm.custom.functions.datetime' => [],
            'orm.custom.hydration_modes' => [],
            'orm.class_metadata_factory_name' => 'Doctrine\ORM\Mapping\ClassMetadataFactory',
            'orm.default_repository_class' => 'Doctrine\ORM\EntityRepository',
        ];
    }
}
