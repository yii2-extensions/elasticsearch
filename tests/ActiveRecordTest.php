<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch;

use yii\base\Event;
use yii\base\InvalidCallException;
use yii\db\BaseActiveRecord;
use yii\elasticsearch\Connection;
use yii\elasticsearch\tests\helpers\Record;
use yiiunit\extensions\elasticsearch\data\ar\ActiveRecord;
use yiiunit\extensions\elasticsearch\data\ar\Customer;
use yiiunit\extensions\elasticsearch\data\ar\OrderItem;
use yiiunit\extensions\elasticsearch\data\ar\Order;
use yiiunit\extensions\elasticsearch\data\ar\Item;
use yiiunit\extensions\elasticsearch\data\ar\Animal;
use yiiunit\extensions\elasticsearch\data\ar\Dog;
use yiiunit\extensions\elasticsearch\data\ar\Cat;

/**
 * @group elasticsearch
 */
class ActiveRecordTest extends TestCase
{
    use ActiveRecordTestTrait;

    public function getCustomerClass()
    {
        return Customer::class;
    }

    public function getItemClass()
    {
        return Item::class;
    }

    public function getOrderClass()
    {
        return Order::class;
    }

    public function getOrderItemClass()
    {
        return OrderItem::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        /* @var $db Connection */
        $db = ActiveRecord::$db = $this->getConnection();

        Record::initIndex(Customer::class, $db);
        Record::initIndex(Item::class, $db);
        Record::initIndex(Order::class, $db);
        Record::initIndex(OrderItem::class, $db);
        Record::initIndex(Animal::class, $db);

        Record::insertMany(
            Customer::class,
            [
                [
                    '_id' => 1,
                    'email' => 'user1@example.com',
                    'name' => 'user1',
                    'address' => 'address1',
                    'status' => 1,
                    'is_active' => true,
                ],
                [
                    '_id' => 2,
                    'email' => 'user2@example.com',
                    'name' => 'user2',
                    'address' => 'address2',
                    'status' => 1,
                    'is_active' => true,
                ],
                [
                    '_id' => 3,
                    'email' => 'user3@example.com',
                    'name' => 'user3',
                    'address' => 'address3',
                    'status' => 2,
                    'is_active' => false,
                ],
            ],
        );

        Record::refreshIndex(Customer::class, $db);

        Record::insertMany(
            Item::class,
            [
                [
                    '_id' => 1,
                    'name' => 'Agile Web Application Development with Yii1.1 and PHP5',
                    'category_id' => 1,
                ],
                [
                    '_id' => 2,
                    'name' => 'Yii 1.1 Application Development Cookbook',
                    'category_id' => 1,
                ],
                [
                    '_id' => 3,
                    'name' => 'Ice Age',
                    'category_id' => 2,
                ],
                [
                    '_id' => 4,
                    'name' => 'Toy Story',
                    'category_id' => 2,
                ],
                [
                    '_id' => 5,
                    'name' => 'Cars',
                    'category_id' => 2,
                ],
            ],
        );

        Record::refreshIndex(Item::class, $db);

        Record::insertMany(
            Order::class,
            [
                [
                    '_id' => 1,
                    'customer_id' => 1,
                    'created_at' => 1_325_282_384,
                    'total' => 110.0,
                    'itemsArray' => [1, 2],
                ],
                [
                    '_id' => 2,
                    'customer_id' => 2,
                    'created_at' => 1_325_334_482,
                    'total' => 33.0,
                    'itemsArray' => [4, 5, 3],
                ],
                [
                    '_id' => 3,
                    'customer_id' => 2,
                    'created_at' => 1_325_502_201,
                    'total' => 40.0,
                    'itemsArray' => [2],
                ],
            ],
        );

        Record::refreshIndex(Order::class, $db);

        Record::insertMany(
            OrderItem::class,
            [
                ['order_id' => 1, 'item_id' => 1, 'quantity' => 1, 'subtotal' => 30.0],
                ['order_id' => 1, 'item_id' => 2, 'quantity' => 2, 'subtotal' => 40.0],
                ['order_id' => 2, 'item_id' => 4, 'quantity' => 1, 'subtotal' => 10.0],
                ['order_id' => 2, 'item_id' => 5, 'quantity' => 1, 'subtotal' => 15.0],
                ['order_id' => 2, 'item_id' => 3, 'quantity' => 1, 'subtotal' => 8.0],
                ['order_id' => 3, 'item_id' => 2, 'quantity' => 1, 'subtotal' => 40.0],
            ],
        );

        Record::refreshIndex(OrderItem::class, $db);
        Record::insert(Cat::class, []);
        Record::insert(Dog::class, []);
        Record::refreshIndex(Animal::class, $db);
    }

    public function testSaveNoChanges(): void
    {
        // this should not fail with exception
        $customer = new Customer();

        // insert
        $this->assertTrue($customer->save(false));

        // update
        $this->assertTrue($customer->save(false));
    }

    public function testFindAsArray(): void
    {
        // asArray
        $customer = Customer::find()->where(['_id' => 2])->asArray()->one();

        $this->assertEquals(
            [
                'email' => 'user2@example.com',
                'name' => 'user2',
                'address' => 'address2',
                'status' => 1,
                'is_active' => true,
            ],
            $customer['_source'],
        );
        $this->assertEquals(2, $customer['_id']);
    }

    public function testSearch(): void
    {
        $customers = Customer::find()->search()['hits'];

        $total = is_array($customers['total']) ? $customers['total']['value'] : $customers['total'];
        $this->assertEquals(3, $total);
        $this->assertTrue($customers['hits'][0] instanceof Customer);
        $this->assertTrue($customers['hits'][1] instanceof Customer);
        $this->assertTrue($customers['hits'][2] instanceof Customer);

        // limit vs. totalcount
        $customers = Customer::find()->limit(2)->search()['hits'];
        $total = is_array($customers['total']) ? $customers['total']['value'] : $customers['total'];
        $this->assertEquals(3, $total);
        $this->assertCount(2, $customers['hits']);

        // asArray
        $result = Customer::find()->asArray()->search()['hits'];
        $total = is_array($result['total']) ? $result['total']['value'] : $result['total'];
        $this->assertEquals(3, $total);

        $customers = $result['hits'];
        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('_id', $customers[0]);
        $this->assertArrayHasKey('name', $customers[0]['_source']);
        $this->assertArrayHasKey('email', $customers[0]['_source']);
        $this->assertArrayHasKey('address', $customers[0]['_source']);
        $this->assertArrayHasKey('status', $customers[0]['_source']);
        $this->assertArrayHasKey('_id', $customers[1]);
        $this->assertArrayHasKey('name', $customers[1]['_source']);
        $this->assertArrayHasKey('email', $customers[1]['_source']);
        $this->assertArrayHasKey('address', $customers[1]['_source']);
        $this->assertArrayHasKey('status', $customers[1]['_source']);
        $this->assertArrayHasKey('_id', $customers[2]);
        $this->assertArrayHasKey('name', $customers[2]['_source']);
        $this->assertArrayHasKey('email', $customers[2]['_source']);
        $this->assertArrayHasKey('address', $customers[2]['_source']);
        $this->assertArrayHasKey('status', $customers[2]['_source']);

        // TODO test asArray() + fields() + indexBy()
        // find by attributes
        $result = Customer::find()->where(['name' => 'user2'])->search()['hits'];
        $customer = reset($result['hits']);
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEquals(2, $customer->_id);
    }

    public function testSuggestion(): void
    {
        $result = Customer::find()
            ->addSuggester(
                'customer_name',
                [
                    'text' => 'user',
                    'term' => [
                        'field' => 'name',
                    ],
                ],
            )
            ->search();

        $this->assertCount(3, $result['suggest']['customer_name'][0]['options']);
    }

    public function testGetDb(): void
    {
        $this->mockApplication(['components' => ['elasticsearch' => Connection::class]]);
        $this->assertInstanceOf(Connection::class, ActiveRecord::getDb());
    }

    public function testGet(): void
    {
        $this->assertInstanceOf(Customer::class, Customer::get(1));
        $this->assertNull(Customer::get(5));
    }

    public function testMget(): void
    {
        $this->assertEquals([], Customer::mget([]));

        $records = Customer::mget([1]);
        $this->assertCount(1, $records);
        $this->assertInstanceOf(Customer::class, reset($records));

        $records = Customer::mget([5]);
        $this->assertCount(0, $records);

        $records = Customer::mget([1, 3, 5]);
        $this->assertCount(2, $records);
        $this->assertInstanceOf(Customer::class, $records[0]);
        $this->assertInstanceOf(Customer::class, $records[1]);
    }

    public function testFindLazy(): void
    {
        /* @var $customer Customer */
        $customer = Customer::findOne(2);
        $orders = $customer->orders;
        $this->assertCount(2, $orders);

        $orders = $customer->getOrders()->where(['between', 'created_at', 1_325_334_000, 1_325_400_000])->all();
        $this->assertCount(1, $orders);
        $this->assertEquals(2, $orders[0]->_id);
    }

    public function testFindEagerViaRelation(): void
    {
        $orders = Order::find()->with('items')->orderBy('created_at')->all();

        $this->assertCount(3, $orders);

        $order = $orders[0];
        $this->assertEquals(1, $order->_id);
        $this->assertTrue($order->isRelationPopulated('items'));
        $this->assertCount(2, $order->items);
        $this->assertEquals(1, $order->items[0]->_id);
        $this->assertEquals(2, $order->items[1]->_id);
    }

    public function testInsertNoPk(): void
    {
        $this->assertEquals(['_id'], Customer::primaryKey());

        $customer = new Customer();
        $customer->email = 'user4@example.com';
        $customer->name = 'user4';
        $customer->address = 'address4';

        $this->assertNull($customer->_id);
        $this->assertNull($customer->oldPrimaryKey);
        $this->assertNull($customer->_id);
        $this->assertTrue($customer->isNewRecord);

        $customer->save();

        Record::refreshIndex($customer::class, $customer->db);

        $this->assertNotNull($customer->_id);
        $this->assertNotNull($customer->oldPrimaryKey);
        $this->assertNotNull($customer->_id);
        $this->assertEquals($customer->_id, $customer->oldPrimaryKey);
        $this->assertEquals($customer->_id, $customer->_id);
        $this->assertFalse($customer->isNewRecord);
    }

    public function testInsertPk(): void
    {
        $customer = new Customer();
        $customer->_id = 5;
        $customer->email = 'user5@example.com';
        $customer->name = 'user5';
        $customer->address = 'address5';

        $this->assertTrue($customer->isNewRecord);

        $customer->save();

        $this->assertEquals(5, $customer->_id);
        $this->assertEquals(5, $customer->oldPrimaryKey);
        $this->assertEquals(5, $customer->_id);
        $this->assertFalse($customer->isNewRecord);
    }

    public function testFindLazyVia2(): void
    {
        /* @var $this TestCase|ActiveRecordTestTrait */
        /* @var $order Order */
        $orderClass = $this->getOrderClass();

        $order = new $orderClass();
        $order->_id = 100;
        $this->assertEquals([], $order->items);
    }

    public function testScriptFields(): void
    {
        $orderItems = OrderItem::find()
            ->source('quantity', 'subtotal')
            ->scriptFields(
                [
                    'total' => [
                        'script' => [
                            'lang' => 'painless',
                            'inline' => "doc['quantity'].value * doc['subtotal'].value",
                        ],
                    ],
                ],
            )
            ->all();

        $this->assertNotEmpty($orderItems);

        foreach ($orderItems as $item) {
            $this->assertEquals($item->subtotal * $item->quantity, $item->total);
        }
    }

    public function testFindAsArrayFields(): void
    {
        /* @var $this TestCase|ActiveRecordTestTrait */
        // indexBy + asArray
        $customers = Customer::find()->asArray()->storedFields(['name'])->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers[0]['fields']);
        $this->assertArrayNotHasKey('email', $customers[0]['fields']);
        $this->assertArrayNotHasKey('address', $customers[0]['fields']);
        $this->assertArrayNotHasKey('status', $customers[0]['fields']);
        $this->assertArrayHasKey('name', $customers[1]['fields']);
        $this->assertArrayNotHasKey('email', $customers[1]['fields']);
        $this->assertArrayNotHasKey('address', $customers[1]['fields']);
        $this->assertArrayNotHasKey('status', $customers[1]['fields']);
        $this->assertArrayHasKey('name', $customers[2]['fields']);
        $this->assertArrayNotHasKey('email', $customers[2]['fields']);
        $this->assertArrayNotHasKey('address', $customers[2]['fields']);
        $this->assertArrayNotHasKey('status', $customers[2]['fields']);
    }

    public function testFindAsArraySourceFilter(): void
    {
        /* @var $this TestCase|ActiveRecordTestTrait */
        // indexBy + asArray
        $customers = Customer::find()->asArray()->source(['name'])->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers[0]['_source']);
        $this->assertArrayNotHasKey('email', $customers[0]['_source']);
        $this->assertArrayNotHasKey('address', $customers[0]['_source']);
        $this->assertArrayNotHasKey('status', $customers[0]['_source']);
        $this->assertArrayHasKey('name', $customers[1]['_source']);
        $this->assertArrayNotHasKey('email', $customers[1]['_source']);
        $this->assertArrayNotHasKey('address', $customers[1]['_source']);
        $this->assertArrayNotHasKey('status', $customers[1]['_source']);
        $this->assertArrayHasKey('name', $customers[2]['_source']);
        $this->assertArrayNotHasKey('email', $customers[2]['_source']);
        $this->assertArrayNotHasKey('address', $customers[2]['_source']);
        $this->assertArrayNotHasKey('status', $customers[2]['_source']);
    }

    public function testFindIndexBySource(): void
    {
        $customerClass = $this->getCustomerClass();

        /* @var $this TestCase|ActiveRecordTestTrait */
        // indexBy + asArray
        $customers = Customer::find()->indexBy('name')->source('name')->all();

        $this->assertCount(3, $customers);
        $this->assertTrue($customers['user1'] instanceof $customerClass);
        $this->assertTrue($customers['user2'] instanceof $customerClass);
        $this->assertTrue($customers['user3'] instanceof $customerClass);
        $this->assertNotNull($customers['user1']->name);
        $this->assertNull($customers['user1']->email);
        $this->assertNull($customers['user1']->address);
        $this->assertNull($customers['user1']->status);
        $this->assertNotNull($customers['user2']->name);
        $this->assertNull($customers['user2']->email);
        $this->assertNull($customers['user2']->address);
        $this->assertNull($customers['user2']->status);
        $this->assertNotNull($customers['user3']->name);
        $this->assertNull($customers['user3']->email);
        $this->assertNull($customers['user3']->address);
        $this->assertNull($customers['user3']->status);

        // indexBy callable + asArray
        $customers = Customer::find()
            ->indexBy(fn($customer) => $customer->_id . '-' . $customer->name)
            ->storedFields('name')
            ->all();

        $this->assertCount(3, $customers);
        $this->assertTrue($customers['1-user1'] instanceof $customerClass);
        $this->assertTrue($customers['2-user2'] instanceof $customerClass);
        $this->assertTrue($customers['3-user3'] instanceof $customerClass);
        $this->assertNotNull($customers['1-user1']->name);
        $this->assertNull($customers['1-user1']->email);
        $this->assertNull($customers['1-user1']->address);
        $this->assertNull($customers['1-user1']->status);
        $this->assertNotNull($customers['2-user2']->name);
        $this->assertNull($customers['2-user2']->email);
        $this->assertNull($customers['2-user2']->address);
        $this->assertNull($customers['2-user2']->status);
        $this->assertNotNull($customers['3-user3']->name);
        $this->assertNull($customers['3-user3']->email);
        $this->assertNull($customers['3-user3']->address);
        $this->assertNull($customers['3-user3']->status);
    }

    public function testFindIndexByAsArrayFields(): void
    {
        /* @var $this TestCase|ActiveRecordTestTrait */
        // indexBy + asArray
        $customers = Customer::find()->indexBy('name')->asArray()->storedFields('name')->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers['user1']['fields']);
        $this->assertArrayNotHasKey('email', $customers['user1']['fields']);
        $this->assertArrayNotHasKey('address', $customers['user1']['fields']);
        $this->assertArrayNotHasKey('status', $customers['user1']['fields']);
        $this->assertArrayHasKey('name', $customers['user2']['fields']);
        $this->assertArrayNotHasKey('email', $customers['user2']['fields']);
        $this->assertArrayNotHasKey('address', $customers['user2']['fields']);
        $this->assertArrayNotHasKey('status', $customers['user2']['fields']);
        $this->assertArrayHasKey('name', $customers['user3']['fields']);
        $this->assertArrayNotHasKey('email', $customers['user3']['fields']);
        $this->assertArrayNotHasKey('address', $customers['user3']['fields']);
        $this->assertArrayNotHasKey('status', $customers['user3']['fields']);

        // indexBy callable + asArray
        $customers = Customer::find()
            ->indexBy(fn($customer) => $customer['_id'] . '-' . reset($customer['fields']['name']))
            ->asArray()
            ->storedFields('name')
            ->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers['1-user1']['fields']);
        $this->assertArrayNotHasKey('email', $customers['1-user1']['fields']);
        $this->assertArrayNotHasKey('address', $customers['1-user1']['fields']);
        $this->assertArrayNotHasKey('status', $customers['1-user1']['fields']);
        $this->assertArrayHasKey('name', $customers['2-user2']['fields']);
        $this->assertArrayNotHasKey('email', $customers['2-user2']['fields']);
        $this->assertArrayNotHasKey('address', $customers['2-user2']['fields']);
        $this->assertArrayNotHasKey('status', $customers['2-user2']['fields']);
        $this->assertArrayHasKey('name', $customers['3-user3']['fields']);
        $this->assertArrayNotHasKey('email', $customers['3-user3']['fields']);
        $this->assertArrayNotHasKey('address', $customers['3-user3']['fields']);
        $this->assertArrayNotHasKey('status', $customers['3-user3']['fields']);
    }

    public function testFindIndexByAsArray(): void
    {
        /* @var $customerClass \yii\db\ActiveRecordInterface */
        $customerClass = $this->getCustomerClass();

        /* @var $this TestCase|ActiveRecordTestTrait */
        // indexBy + asArray
        $customers = $customerClass::find()->asArray()->indexBy('name')->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers['user1']['_source']);
        $this->assertArrayHasKey('email', $customers['user1']['_source']);
        $this->assertArrayHasKey('address', $customers['user1']['_source']);
        $this->assertArrayHasKey('status', $customers['user1']['_source']);
        $this->assertArrayHasKey('name', $customers['user2']['_source']);
        $this->assertArrayHasKey('email', $customers['user2']['_source']);
        $this->assertArrayHasKey('address', $customers['user2']['_source']);
        $this->assertArrayHasKey('status', $customers['user2']['_source']);
        $this->assertArrayHasKey('name', $customers['user3']['_source']);
        $this->assertArrayHasKey('email', $customers['user3']['_source']);
        $this->assertArrayHasKey('address', $customers['user3']['_source']);
        $this->assertArrayHasKey('status', $customers['user3']['_source']);

        // indexBy callable + asArray
        $customers = $customerClass::find()
            ->indexBy(fn($customer) => $customer['_id'] . '-' . $customer['_source']['name'])
            ->asArray()
            ->all();

        $this->assertCount(3, $customers);
        $this->assertArrayHasKey('name', $customers['1-user1']['_source']);
        $this->assertArrayHasKey('email', $customers['1-user1']['_source']);
        $this->assertArrayHasKey('address', $customers['1-user1']['_source']);
        $this->assertArrayHasKey('status', $customers['1-user1']['_source']);
        $this->assertArrayHasKey('name', $customers['2-user2']['_source']);
        $this->assertArrayHasKey('email', $customers['2-user2']['_source']);
        $this->assertArrayHasKey('address', $customers['2-user2']['_source']);
        $this->assertArrayHasKey('status', $customers['2-user2']['_source']);
        $this->assertArrayHasKey('name', $customers['3-user3']['_source']);
        $this->assertArrayHasKey('email', $customers['3-user3']['_source']);
        $this->assertArrayHasKey('address', $customers['3-user3']['_source']);
        $this->assertArrayHasKey('status', $customers['3-user3']['_source']);
    }

    public function testAfterFindGet(): void
    {
        /* @var $customerClass BaseActiveRecord */
        $customerClass = $this->getCustomerClass();

        $afterFindCalls = [];
        Event::on(
            BaseActiveRecord::class,
            BaseActiveRecord::EVENT_AFTER_FIND,
            static function ($event) use (&$afterFindCalls): void {
                /* @var $ar BaseActiveRecord */
                $ar = $event->sender;
                $afterFindCalls[] = [
                    $ar::class,
                    $ar->getIsNewRecord(),
                    $ar->getPrimaryKey(),
                    $ar->isRelationPopulated('orders'),
                ];
            },
        );

        $customer = Customer::get(1);
        $this->assertNotNull($customer);
        $this->assertEquals([[$customerClass, false, 1, false]], $afterFindCalls);

        $afterFindCalls = [];
        $customer = Customer::mget([1, 2]);

        $this->assertNotNull($customer);
        $this->assertEquals([[$customerClass, false, 1, false], [$customerClass, false, 2, false]], $afterFindCalls);

        $afterFindCalls = [];

        Event::off(BaseActiveRecord::class, BaseActiveRecord::EVENT_AFTER_FIND);
    }

    public function testFindEmptyPkCondition(): void
    {
        /* @var $this TestCase|ActiveRecordTestTrait */
        /* @var $orderItemClass \yii\db\ActiveRecordInterface */
        $orderItemClass = $this->getOrderItemClass();

        $orderItem = new $orderItemClass();
        $orderItem->setAttributes(['order_id' => 1, 'item_id' => 1, 'quantity' => 1, 'subtotal' => 30.0], false);
        $orderItem->save(false);

        Record::refreshIndex($orderItem::class, $orderItem->db);

        $orderItems = $orderItemClass::find()->where(['_id' => [$orderItem->getPrimaryKey()]])->all();
        $this->assertCount(1, $orderItems);

        $orderItems = $orderItemClass::find()->where(['_id' => []])->all();
        $this->assertCount(0, $orderItems);

        $orderItems = $orderItemClass::find()->where(['_id' => null])->all();
        $this->assertCount(0, $orderItems);

        $orderItems = $orderItemClass::find()->where(['IN', '_id', [$orderItem->getPrimaryKey()]])->all();
        $this->assertCount(1, $orderItems);

        $orderItems = $orderItemClass::find()->where(['IN', '_id', []])->all();
        $this->assertCount(0, $orderItems);

        $orderItems = $orderItemClass::find()->where(['IN', '_id', [null]])->all();
        $this->assertCount(0, $orderItems);
    }

    public function testArrayAttributes(): void
    {
        $this->assertIsArray(Order::findOne(1)->itemsArray);
        $this->assertIsArray(Order::findOne(2)->itemsArray);
        $this->assertIsArray(Order::findOne(3)->itemsArray);
    }

    public function testArrayAttributeRelationLazy(): void
    {
        $order = Order::findOne(1);
        $items = $order->itemsByArrayValue;

        $this->assertCount(2, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertTrue(isset($items[2]));
        $this->assertTrue($items[1] instanceof Item);
        $this->assertTrue($items[2] instanceof Item);

        $order = Order::findOne(2);
        $items = $order->itemsByArrayValue;

        $this->assertCount(3, $items);
        $this->assertTrue(isset($items[3]));
        $this->assertTrue(isset($items[4]));
        $this->assertTrue(isset($items[5]));
        $this->assertTrue($items[3] instanceof Item);
        $this->assertTrue($items[4] instanceof Item);
        $this->assertTrue($items[5] instanceof Item);
    }

    public function testArrayAttributeRelationEager(): void
    {
        /* @var $order Order */
        $order = Order::find()->with('itemsByArrayValue')->where(['_id' => 1])->one();

        $this->assertTrue($order->isRelationPopulated('itemsByArrayValue'));

        $items = $order->itemsByArrayValue;

        $this->assertCount(2, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertTrue(isset($items[2]));
        $this->assertTrue($items[1] instanceof Item);
        $this->assertTrue($items[2] instanceof Item);

        /* @var $order Order */
        $order = Order::find()->with('itemsByArrayValue')->where(['_id' => 2])->one();

        $this->assertTrue($order->isRelationPopulated('itemsByArrayValue'));

        $items = $order->itemsByArrayValue;

        $this->assertCount(3, $items);
        $this->assertTrue(isset($items[3]));
        $this->assertTrue(isset($items[4]));
        $this->assertTrue(isset($items[5]));
        $this->assertTrue($items[3] instanceof Item);
        $this->assertTrue($items[4] instanceof Item);
        $this->assertTrue($items[5] instanceof Item);
    }

    public function testArrayAttributeRelationLink(): void
    {
        /* @var $order Order */
        $order = Order::find()->where(['_id' => 1])->one();
        $items = $order->itemsByArrayValue;

        $this->assertCount(2, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertTrue(isset($items[2]));

        $item = Item::get(5);

        try {
            $order->link('itemsByArrayValue', $item);
        } catch (InvalidCallException $e) {
            $this->assertEquals(
                $e->getMessage(),
                'Unable to link models: foreign model cannot be linked if its property is an array.',
            );
        }
    }

    public function testArrayAttributeRelationUnLink(): void
    {
        /* @var $order Order */
        $order = Order::find()->where(['_id' => 1])->one();
        $items = $order->itemsByArrayValue;

        $this->assertCount(2, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertTrue(isset($items[2]));

        $item = Item::get(2);
        $order->unlink('itemsByArrayValue', $item);
        Record::refreshIndex($order::class, $order->db);

        $items = $order->itemsByArrayValue;
        $this->assertCount(1, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertFalse(isset($items[2]));

        // check also after refresh
        $this->assertTrue($order->refresh());
        $items = $order->itemsByArrayValue;
        $this->assertCount(1, $items);
        $this->assertTrue(isset($items[1]));
        $this->assertFalse(isset($items[2]));
    }

    /**
     * https://github.com/yiisoft/yii2/issues/6065
     */
    public function testArrayAttributeRelationUnLinkBrokenArray(): void
    {
        /* @var $order Order */
        $order = Order::find()->where(['_id' => 1])->one();

        $itemIds = $order->itemsArray;
        $removeId = reset($itemIds);
        $item = Item::get($removeId);
        $order->unlink('itemsByArrayValue', $item);
        Record::refreshIndex($order::class, $order->db);

        $items = $order->itemsByArrayValue;
        $this->assertCount(1, $items);
        $this->assertFalse(isset($items[$removeId]));

        // check also after refresh
        $this->assertTrue($order->refresh());
        $items = $order->itemsByArrayValue;
        $this->assertCount(1, $items);
        $this->assertFalse(isset($items[$removeId]));
    }

    public function testUnlinkAllNotSupported(): void
    {
        try {
            /* @var $order Order */
            $order = Order::find()->where(['_id' => 1])->one();

            $items = $order->itemsByArrayValue;
            $this->assertCount(2, $items);
            $this->assertTrue(isset($items[1]));
            $this->assertTrue(isset($items[2]));

            $order->unlinkAll('itemsByArrayValue');
        } catch (\yii\base\NotSupportedException $e) {
            $this->assertEquals(
                $e->getMessage(),
                'unlinkAll() is not supported by Elasticsearch, use unlink() instead.',
            );
        }
    }

    public function testPopulateRecordCallWhenQueryingOnParentClass(): void
    {
        $animal = Animal::find()->where(['species' => Dog::class])->one();
        $this->assertEquals('bark', $animal->getDoes());

        $animal = Animal::find()->where(['species' => Cat::class])->one();
        $this->assertEquals('meow', $animal->getDoes());
    }

    public function testAttributeAccess(): void
    {
        /* @var $customerClass \yii\db\ActiveRecordInterface */
        $customerClass = $this->getCustomerClass();
        $model = new $customerClass();

        $this->assertTrue($model->canSetProperty('name'));
        $this->assertTrue($model->canGetProperty('name'));
        $this->assertFalse($model->canSetProperty('unExistingColumn'));
        $this->assertFalse(isset($model->name));

        $model->name = 'foo';
        $this->assertTrue(isset($model->name));
        unset($model->name);
        $this->assertNull($model->name);

        // @see https://github.com/yiisoft/yii2-gii/issues/190
        $baseModel = new $customerClass();
        $this->assertFalse($baseModel->hasProperty('unExistingColumn'));

        /* @var $customer ActiveRecord */
        $customer = new $customerClass();
        $this->assertInstanceOf($customerClass, $customer);

        $this->assertTrue($customer->canGetProperty('_id'));
        $this->assertTrue($customer->canSetProperty('_id'));

        // tests that we really can get and set this property
        $this->assertNull($customer->_id);
        $customer->_id = 10;
        $this->assertNotNull($customer->_id);

        $this->assertFalse($customer->canGetProperty('non_existing_property'));
        $this->assertFalse($customer->canSetProperty('non_existing_property'));
    }

    public function testBooleanAttribute2(): void
    {
        /* @var $customerClass \yii\db\ActiveRecordInterface */
        $customerClass = $this->getCustomerClass();

        $customers = $customerClass::find()->where(['is_active' => true])->all();
        $this->assertCount(2, $customers);

        $customers = $customerClass::find()->where(['is_active' => false])->all();
        $this->assertCount(1, $customers);

        /* @var $this TestCase|ActiveRecordTestTrait */
        $customer = new $customerClass();
        $customer->name = 'boolean customer';
        $customer->email = 'mail@example.com';
        $customer->is_active = true;
        $customer->save(false);
        Record::refreshIndex($customer::class, $customer->db);

        $customer->refresh();
        $this->assertTrue($customer->is_active);

        $customer->is_active = false;
        $res = $customer->save(false);
        Record::refreshIndex($customer::class, $customer->db);

        $customer->refresh();
        $this->assertFalse($customer->is_active);
    }

    // TODO test AR with not mapped PK

    public static function illegalValuesForFindByCondition()
    {
        return [
            [['_id' => ['`id`=`id` and 1' => 1]], null],
            [['_id' => [
                'legal' => 1,
                '`id`=`id` and 1' => 1,
            ]], null],
            [['_id' => [
                'nested_illegal' => [
                    'false or 1=' => 1,
                ],
            ]], null],

            [['_id' => [
                'or',
                '1=1',
                '_id' => '_id',
            ]], null],
            [['_id' => [
                'or',
                '1=1',
                '_id' => '1',
            ]], ['_id' => 1]],
            [['_id' => [
                'name' => 'Cars',
            ]], ['_id' => 5]],
        ];
    }

    /**
     * @dataProvider illegalValuesForFindByCondition
     */
    public function testValueEscapingInFindByCondition(array $filterWithInjection, ?array $expectedResult): void
    {
        /* @var $itemClass \yii\db\ActiveRecordInterface */
        $itemClass = $this->getItemClass();

        $result = $itemClass::findOne($filterWithInjection['_id']);
        if ($expectedResult === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            foreach ($expectedResult as $col => $value) {
                $this->assertEquals($value, $result->$col);
            }
        }
    }
}
