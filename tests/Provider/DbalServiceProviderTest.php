<?php

declare(strict_types=1);

namespace Linio\Doctrine\Provider;

use Doctrine\DBAL\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use Pimple\Container;

class DbalServiceProviderTest extends TestCase
{
    public function testOptionsInitializer(): void
    {
        $container = new Container();
        $container->register(new DbalServiceProvider());

        /** @var Connection $db */
        $db = $container['db'];

        $this->assertEquals($container['db.default_options'], $db->getParams());
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

        /** @var Connection $db */
        $db = $container['db'];

        /** @var Connection[] $dbs */
        $dbs = $container['dbs'];

        /** @var mixed[] $params */
        $params = $db->getParams();

        $this->assertArrayHasKey('memory', $params);
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDO\Sqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $db->fetchOne('SELECT 22'));
        $this->assertSame($dbs['default'], $db);
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

        /** @var Connection $db */
        $db = $container['db'];

        /** @var Connection[] $dbs */
        $dbs = $container['dbs'];

        /** @var mixed[] $params */
        $params = $db->getParams();

        $this->assertArrayHasKey('memory', $params);
        $this->assertTrue($params['memory']);
        $this->assertInstanceof('Doctrine\DBAL\Driver\PDO\Sqlite\Driver', $db->getDriver());
        $this->assertEquals(22, $db->fetchOne('SELECT 22'));

        $this->assertSame($dbs['sqlite1'], $db);

        $db2 = $dbs['sqlite2'];

        /** @var mixed[] $params */
        $params = $db2->getParams();

        $this->assertArrayHasKey('path', $params);
        $this->assertEquals(sys_get_temp_dir() . '/silex', $params['path']);
    }
}
