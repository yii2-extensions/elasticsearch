<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\Command;

/**
 * Class Animal
 *
 * @author Jose Lorente <jose.lorente.martin@gmail.com>
 *
 * @since 2.0
 */
class Animal extends ActiveRecord
{
    public $does;

    public static function index(): string
    {
        return 'animals';
    }

    public static function type(): string
    {
        return 'animal';
    }

    public function attributes()
    {
        return ['species'];
    }

    /**
     * sets up the index for this record
     *
     * @param Command $command
     */
    public static function setUpMapping(Command $command): void
    {
        $command->setMapping(
            static::index(),
            static::type(),
            [
                'properties' => [
                    'species' => ['type' => 'keyword'],
                ],
            ],
        );
    }

    public function init(): void
    {
        parent::init();
        $this->species = static::class;
    }

    public function getDoes()
    {
        return $this->does;
    }

    /**
     * @param type $row
     *
     * @return static
     */
    public static function instantiate($row): static
    {
        $class = $row['_source']['species'];
        return new $class();
    }
}
