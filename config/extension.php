<?php

declare(strict_types=1);

use yii\elasticsearch\Connection;

/**
 * @var array $params
 */
return [
    'components' => [
        'elasticsearch' => [
            'class' => Connection::class,
            'nodes' => $params['yii2.elasticsearch.nodes'] ?? [['http_address' => '127.0.0.1:9200']],
            'dslVersion' => $params['yii2.elasticsearch.dslVersion'] ?? 7,
        ],
    ],
];
