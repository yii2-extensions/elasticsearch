<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch\data\ar;

/**
 * Class Dog
 *
 * @author Jose Lorente <jose.lorente.martin@gmail.com>
 */
class Dog extends Animal
{
    /**
     *
     * @param self $record
     * @param array $row
     */
    public static function populateRecord($record, $row): void
    {
        parent::populateRecord($record, $row);

        $record->does = 'bark';
    }
}
