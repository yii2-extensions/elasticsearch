<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\Command;

/**
 * Class OrderItem
 *
 * @property int $order_id
 * @property int $item_id
 * @property int $quantity
 * @property string $subtotal
 */
class OrderItem extends ActiveRecord
{
    public $total;

    public function attributes()
    {
        return ['order_id', 'item_id', 'quantity', 'subtotal'];
    }

    public function getOrder()
    {
        return $this->hasOne(Order::className(), ['_id' => 'order_id']);
    }

    public function getItem()
    {
        return $this->hasOne(Item::className(), ['_id' => 'item_id']);
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
                'order_id' => ['type' => 'integer'],
                'item_id' => ['type' => 'integer'],
                'quantity' => ['type' => 'integer'],
                'subtotal' => ['type' => 'integer'],
            ],
        ]);
    }
}
