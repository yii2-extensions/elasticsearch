<p align="center">
    <a href="https://github.com/yii2-extensions/elasticsearch" target="_blank">
        <img src="https://www.yiiframework.com/image/yii_logo_light.svg" height="100px;">
    </a>
    <h1 align="center">Elasticsearch Query and ActiveRecord.</h1>
    <br>
</p>

<p align="center">
    <a href="https://www.php.net/releases/8.1/en.php" target="_blank">
        <img src="https://img.shields.io/badge/PHP-%3E%3D8.1-787CB5" alt="php-version">
    </a>
    <a href="https://github.com/yiisoft/yii2/tree/2.2" target="_blank">
        <img src="https://img.shields.io/badge/Yii2%20version-2.2-blue" alt="yii2-version">
    </a>
    <a href="https://github.com/yii2-extensions/elasticsearch/actions/workflows/build.yml" target="_blank">
        <img src="https://github.com/yii2-extensions/elasticsearch/actions/workflows/build.yml/badge.svg" alt="PHPUnit">
    </a>
    <a href="https://codecov.io/gh/yii2-extensions/elasticsearch" target="_blank">
        <img src="https://codecov.io/gh/yii2-extensions/elasticsearch/branch/main/graph/badge.svg?token=MF0XUGVLYC" alt="Codecov">
    </a>
    <a href="https://github.com/yii2-extensions/elasticsearch/actions/workflows/static.yml" target="_blank">
        <img src="https://github.com/yii2-extensions/elasticsearch/actions/workflows/static.yml/badge.svg" alt="PHPStan">
    </a>
    <a href="https://github.com/yii2-extensions/elasticsearch/actions/workflows/static.yml" target="_blank">
        <img src="https://img.shields.io/badge/PHPStan%20level-1-blue" alt="PHPStan level">
    </a>    
    <a href="https://github.styleci.io/repos/710193992?branch=main" target="_blank">
        <img src="https://github.styleci.io/repos/710193992/shield?branch=main" alt="Code style">
    </a>    
</p>

This extension provides the [Elasticsearch](https://www.elastic.co/products/elasticsearch) integration for the [Yii framework 2.0](https://www.yiiframework.com).
It includes basic querying/search support and also implements the `ActiveRecord` pattern that allows you to store active
records in Elasticsearch.

## Installation

The preferred way to install this extension is through [composer](https://getcomposer.org/download/).

Either run

```
php composer.phar require --dev --prefer-dist yii2-extensions/elasticsearch
```

or add

```
"yii2-extensions/elasticsearch": "dev-main"
```

to the require section of your `composer.json` file.

## Usage

To use this extension, you have to configure the Connection class in your application configuration:

```php
use yii\elasticsearch\Connection;

return [
    //....
    'components' => [
        'elasticsearch' => [
            'class' => Connection::class,
            'nodes' => [
                ['http_address' => '127.0.0.1:9200'],
                // configure more hosts if you have a cluster
            ],
            'dslVersion' => 7, // default is 5
        ],
    ]
];
```

### Configure with yiisoft/config

> Add the following code to your `config/config-plugin` file in your application.

```php
'config-plugin' => [
    'web' => [
        '$yii2-elasticsearch', // add this line
        'web/*.php'
    ],
],
```

## Testing

[Check the documentation testing](/docs/testing.md) to learn about testing.

## Our social networks

[![Twitter](https://img.shields.io/badge/twitter-follow-1DA1F2?logo=twitter&logoColor=1DA1F2&labelColor=555555?style=flat)](https://twitter.com/Terabytesoftw)

## License

The MIT License. Please see [License File](LICENSE.md) for more information.
