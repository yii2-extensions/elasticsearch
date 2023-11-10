<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\Command;

/**
 * Class Item
 *
 * @property int $id
 * @property string $name
 * @property int $category_id
 */
class Item extends ActiveRecord
{
    public function attributes()
    {
        return ['name', 'category_id'];
    }

    /**
     * sets up the index for this record
     *
     * @param Command $command
     */
    public static function setUpMapping($command): void
    {
        $command->setMapping(static::index(), static::type(), [
            'properties' => [
                'name' => ['type' => 'keyword', 'store' => true],
                'category_id' => ['type' => 'integer'],
            ],
        ]);
    }
}
