<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\Connection;

/**
 * ActiveRecord is ...
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 *
 * @since 2.0
 */
class ActiveRecord extends \yii\elasticsearch\ActiveRecord
{
    public static Connection $db;

    /**
     * @return Connection
     */
    public static function getDb(): Connection
    {
        return self::$db;
    }
}
