<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch;

use yii\elasticsearch\Connection;

/**
 * @group elasticsearch
 */
class ElasticsearchConnectionTest extends TestCase
{
    public function testOpen(): void
    {
        $connection = new Connection();
        $connection->autodetectCluster;
        $connection->nodes = [
            ['http_address' => 'inet[/127.0.0.1:9200]'],
        ];
        $this->assertNull($connection->activeNode);
        $connection->open();
        $this->assertNotNull($connection->activeNode);
        $this->assertArrayHasKey('name', reset($connection->nodes));
//        $this->assertArrayHasKey('hostname', reset($connection->nodes));
        $this->assertArrayHasKey('version', reset($connection->nodes));
        $this->assertArrayHasKey('http_address', reset($connection->nodes));
    }
}
