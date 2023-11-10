<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\Command;
use yiiunit\extensions\elasticsearch\ActiveRecordTest;

/**
 * Class Customer
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $address
 * @property int $status
 * @property bool $is_active
 */
class Customer extends ActiveRecord
{
    final public const STATUS_ACTIVE = 1;
    final public const STATUS_INACTIVE = 2;

    public function attributes()
    {
        return ['name', 'email', 'address', 'status', 'is_active'];
    }

    public function getOrders()
    {
        return $this->hasMany(Order::className(), ['customer_id' => '_id'])->orderBy('created_at');
    }

    public function getExpensiveOrders()
    {
        return $this->hasMany(Order::className(), ['customer_id' => '_id'])
            ->where([ 'gte', 'total', 50 ])
            ->orderBy('_id');
    }

    public function getOrdersWithItems()
    {
        return $this->hasMany(Order::className(), ['customer_id' => '_id'])->with('orderItems');
    }

    public function afterSave($insert, $changedAttributes): void
    {
        ActiveRecordTest::$afterSaveInsert = $insert;
        ActiveRecordTest::$afterSaveNewRecord = $this->isNewRecord;
        parent::afterSave($insert, $changedAttributes);
    }

    /**
     * sets up the index for this record
     *
     * @param Command $command
     * @param bool $statusIsBoolean
     */
    public static function setUpMapping($command): void
    {
        $command->setMapping(static::index(), static::type(), [
            'properties' => [
                'name' => ['type' => 'keyword',  'store' => true],
                'email' => ['type' => 'keyword', 'store' => true],
                'address' => ['type' => 'text'],
                'status' => ['type' => 'integer', 'store' => true],
                'is_active' => ['type' => 'boolean', 'store' => true],
            ],
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return CustomerQuery
     */
    public static function find()
    {
        return new CustomerQuery(static::class);
    }
}
