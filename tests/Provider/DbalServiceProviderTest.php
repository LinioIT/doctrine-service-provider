<?php

namespace Linio\Doctrine\Provider;

use Pimple\Container;

class DbalServiceProviderTest extends \PHPUnit_Framework_TestCase
{
    public function testOptionsInitializer()
    {
        $container = new Container();
        $container->register(new DbalServiceProvider());

        $this->assertEquals($container['db.default_options'], $container['db']->getParams());
    }

    public function testSingleConnection()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $container = new Container();
        $container->register(new DbalServiceProvider(), array(
            'db.options' => array('driver' => 'pdo_sqlite', 'memory' => true),
        ));

        $db = $container['db'];
        $params = $db->getParams();
        $this->assertTrue(array_key_exists('memory', $params));
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDOSqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $container['db']->fetchColumn('SELECT 22'));

        $this->assertSame($container['dbs']['default'], $db);
    }

    public function testMultipleConnections()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('pdo_sqlite is not available');
        }

        $container = new Container();
        $container->register(new DbalServiceProvider(), array(
            'dbs.options' => array(
                'sqlite1' => array('driver' => 'pdo_sqlite', 'memory' => true),
                'sqlite2' => array('driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir().'/silex'),
            ),
        ));

        $db = $container['db'];
        $params = $db->getParams();
        $this->assertTrue(array_key_exists('memory', $params));
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDOSqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $container['db']->fetchColumn('SELECT 22'));

        $this->assertSame($container['dbs']['sqlite1'], $db);

        $db2 = $container['dbs']['sqlite2'];
        $params = $db2->getParams();
        $this->assertTrue(array_key_exists('path', $params));
        $this->assertEquals(sys_get_temp_dir().'/silex', $params['path']);
    }
}
