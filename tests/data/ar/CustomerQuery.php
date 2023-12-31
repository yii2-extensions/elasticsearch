<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

use yii\elasticsearch\ActiveQuery;

/**
 * CustomerQuery
 */
class CustomerQuery extends ActiveQuery
{
    public function active(): static
    {
        $this->andWhere(['status' => 1]);

        return $this;
    }
}
