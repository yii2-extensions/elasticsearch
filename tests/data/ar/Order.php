<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\db\ActiveQueryInterface;
use yii\elasticsearch\ActiveQuery;
use yii\elasticsearch\Command;

/**
 * Class Order
 *
 * @property int $id
 * @property int $customer_id
 * @property int $created_at
 * @property string $total
 * @property array $itemsArray
 * @property Item[] $expensiveItemsUsingViaWithCallable
 * @property Item[] $cheapItemsUsingViaWithCallable
 * @property Item[] $itemsByArrayValue
 */
class Order extends ActiveRecord
{
    public function attributes()
    {
        return ['customer_id', 'created_at', 'total', 'itemsArray'];
    }

    public function getCustomer(): ActiveQueryInterface
    {
        return $this->hasOne(Customer::class, ['_id' => 'customer_id']);
    }

    public function getOrderItems(): ActiveQueryInterface
    {
        return $this->hasMany(OrderItem::class, ['order_id' => '_id']);
    }

    /**
     * A relation to Item defined via array valued attribute
     */
    public function getItemsByArrayValue(): ActiveQueryInterface
    {
        return $this->hasMany(Item::class, ['_id' => 'itemsArray'])->indexBy('_id');
    }

    public function getItems(): ActiveQueryInterface
    {
        return $this->hasMany(Item::class, ['_id' => 'item_id'])->via('orderItems')->orderBy('_id');
    }

    public function getExpensiveItemsUsingViaWithCallable(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via(
                'orderItems',
                static function (ActiveQuery $q): void {
                    $q->where(['>=', 'subtotal', 10]);
                },
            );
    }

    public function getCheapItemsUsingViaWithCallable(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via(
                'orderItems',
                static function (ActiveQuery $q): void {
                    $q->where(['<', 'subtotal', 10]);
                },
            );
    }

    public function getItemsIndexed(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via('orderItems')->indexBy('_id');
    }

    public function getItemsInOrder1(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via(
                'orderItems',
                static function ($q): void {
                    $q->orderBy(['subtotal' => SORT_ASC]);
                }
            )
            ->orderBy('name');
    }

    public function getItemsInOrder2(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via(
                'orderItems',
                static function ($q): void {
                    $q->orderBy(['subtotal' => SORT_DESC]);
                }
            )
            ->orderBy('name');
    }

    public function getBooks(): ActiveQueryInterface
    {
        return $this
            ->hasMany(Item::class, ['_id' => 'item_id'])
            ->via('orderItems')
            ->where(['category_id' => 1]);
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
            static::type(), [
                'properties' => [
                    'customer_id' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                ],
            ],
        );
    }
}
