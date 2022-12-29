<?php

declare(strict_types=1);

namespace Linio\Doctrine\Provider;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class DbalServiceProvider implements ServiceProviderInterface
{
    public function register(Container $app): void
    {
        $app['db.default_options'] = [
            'driver' => 'pdo_mysql',
            'dbname' => null,
            'host' => 'localhost',
            'user' => 'root',
            'password' => null,
        ];

        $app['dbs.options.initializer'] = $app->protect(function () use ($app): void {
            static $initialized = false;

            if ($initialized) {
                return;
            }

            $initialized = true;

            if (!isset($app['dbs.options'])) {
                $app['dbs.options'] = ['default' => $app['db.options'] ?? []];
            }

            /** @var mixed[] $tmp */
            $tmp = $app['dbs.options'];

            /** @var mixed[] $defaultOptions */
            $defaultOptions = $app['db.default_options'];

            /** @var mixed[] $options */
            foreach ($tmp as $name => &$options) {
                $options = array_replace($defaultOptions, $options);

                if (!isset($app['dbs.default'])) {
                    $app['dbs.default'] = $name;
                }
            }
            $app['dbs.options'] = $tmp;
        });

        $app['dbs'] = function ($app) {
            $app['dbs.options.initializer']();

            $dbs = new Container();
            foreach ($app['dbs.options'] as $name => $options) {
                if ($app['dbs.default'] === $name) {
                    // we use shortcuts here in case the default has been overridden
                    $config = $app['db.config'];
                    $manager = $app['db.event_manager'];
                } else {
                    $config = $app['dbs.config'][$name];
                    $manager = $app['dbs.event_manager'][$name];
                }

                $dbs[$name] = function ($dbs) use ($options, $config, $manager) {
                    return DriverManager::getConnection($options, $config, $manager);
                };
            }

            return $dbs;
        };

        $app['dbs.config'] = function ($app) {
            $app['dbs.options.initializer']();

            $configs = new Container();
            foreach ($app['dbs.options'] as $name => $options) {
                $configs[$name] = new Configuration();
            }

            return $configs;
        };

        $app['dbs.event_manager'] = function ($app) {
            $app['dbs.options.initializer']();

            $managers = new Container();
            foreach ($app['dbs.options'] as $name => $options) {
                $managers[$name] = new EventManager();
            }

            return $managers;
        };

        // shortcuts for the "first" DB
        $app['db'] = function ($app) {
            $dbs = $app['dbs'];

            return $dbs[$app['dbs.default']];
        };

        $app['db.config'] = function ($app) {
            $dbs = $app['dbs.config'];

            return $dbs[$app['dbs.default']];
        };

        $app['db.event_manager'] = function ($app) {
            $dbs = $app['dbs.event_manager'];

            return $dbs[$app['dbs.default']];
        };
    }
}
