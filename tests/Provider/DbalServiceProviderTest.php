<?php

declare(strict_types=1);

namespace Linio\Doctrine\Provider;

use PDO;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

class DbalServiceProviderTest extends TestCase
{
    public function testOptionsInitializer(): void
    {
        $container = new Container();
        $container->register(new DbalServiceProvider());

        $this->assertEquals($container['db.default_options'], $container['db']->getParams());
    }

    public function testSingleConnection(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $container = new Container();
        $container->register(new DbalServiceProvider(), [
            'db.options' => ['driver' => 'pdo_sqlite', 'memory' => true],
        ]);

        $db = $container['db'];
        $params = $db->getParams();
        $this->assertArrayHasKey('memory', $params);
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDO\Sqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $container['db']->fetchOne('SELECT 22'));
        $this->assertSame($container['dbs']['default'], $db);
    }

    public function testMultipleConnections(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $container = new Container();
        $container->register(new DbalServiceProvider(), [
            'dbs.options' => [
                'sqlite1' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'sqlite2' => ['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir() . '/silex'],
            ],
        ]);

        $db = $container['db'];
        $params = $db->getParams();
        $this->assertArrayHasKey('memory', $params);
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDO\Sqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $container['db']->fetchOne('SELECT 22'));

        $this->assertSame($container['dbs']['sqlite1'], $db);

        $db2 = $container['dbs']['sqlite2'];
        $params = $db2->getParams();
        $this->assertArrayHasKey('path', $params);
        $this->assertEquals(sys_get_temp_dir() . '/silex', $params['path']);
    }
}
