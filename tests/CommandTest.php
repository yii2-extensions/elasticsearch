<?php

declare(strict_types=1);

namespace yiiunit\extensions\elasticsearch;

use yii\elasticsearch\Command;

/**
 * Class CommandTest
 * @package yiiunit\extensions\elasticsearch
 */
class CommandTest extends TestCase
{
    /** @var Command */
    private $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = $this->getConnection()->createCommand();
    }

    /**
     * @test
     */
    public function aliasExists_noAliasesSet_returnsFalse(): void
    {
        $testAlias = 'test';
        $aliasExists = $this->command->aliasExists($testAlias);

        $this->assertFalse($aliasExists);
    }

    /**
     * @test
     */
    public function aliasExists_AliasesAreSetButWithDifferentName_returnsFalse(): void
    {
        $index = 'alias_test';
        $testAlias = 'test';
        $fooAlias1 = 'alias';
        $fooAlias2 = 'alias2';

        $this->command->createIndex($index);
        $this->command->addAlias($index, $fooAlias1);
        $this->command->addAlias($index, $fooAlias2);
        $aliasExists = $this->command->aliasExists($testAlias);
        $this->command->deleteIndex($index);

        $this->assertFalse($aliasExists);
    }

    /**
     * @test
     */
    public function aliasExists_AliasIsSetWithSameName_returnsTrue(): void
    {
        $index = 'alias_test';
        $testAlias = 'test';

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias);
        $aliasExists = $this->command->aliasExists($testAlias);
        $this->command->deleteIndex($index);

        $this->assertTrue($aliasExists);
    }

    /**
     * @test
     */
    public function getAliasInfo_noAliasSet_returnsEmptyArray(): void
    {
        $expectedResult = [];
        $actualResult = $this->command->getAliasInfo();

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     * @dataProvider provideDataForGetAliasInfo
     *
     */
    public function getAliasInfo_singleAliasIsSet_returnsInfoForAlias(
        string $index,
        string $type,
        array $mapping,
        string $alias,
        array $expectedResult,
        array $aliasParameters
    ): void {
        if ($this->command->indexExists($index)) {
            $this->command->deleteIndex($index);
        }
        $this->command->createIndex($index);
        if ($mapping) {
            $this->command->setMapping($index, $type, $mapping);
        }
        $this->command->addAlias($index, $alias, $aliasParameters);
        $actualResult = $this->command->getAliasInfo();
        $this->command->deleteIndex($index);

        // order is not guaranteed
        sort($expectedResult);
        sort($actualResult);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @return array
     */
    public static function provideDataForGetAliasInfo()
    {
        $index = 'alias_test';
        $type = 'alias_test_type';
        $alias = 'test';
        $filter = [
            'filter' => [
                'term' => [
                    'user' => 'satan',
                ],
            ],
        ];
        $mapping = [
            'properties' => [
                'user' => ['type' => 'keyword'],
            ],
        ];
        $singleRouting = [
            'routing' => '1',
        ];
        $singleExpectedRouting = [
            'index_routing' => '1',
            'search_routing' => '1',
        ];
        $differentRouting = [
            'index_routing' => '2',
            'search_routing' => '1,2',
        ];

        return [
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => [],
                        ],
                    ],
                ],
                [],
            ],
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => $filter,
                        ]
                    ],
                ],
                $filter,
            ],
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => $singleExpectedRouting,
                        ],
                    ],
                ],
                $singleRouting,
            ],
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => $differentRouting,
                        ],
                    ],
                ],
                $differentRouting
            ],
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => [...$filter, ...$singleExpectedRouting]
                        ],
                    ],
                ],
                [...$filter, ...$singleRouting],
            ],
            [
                $index,
                $type,
                $mapping,
                $alias,
                [
                    $index => [
                        'aliases' => [
                            $alias => [...$filter, ...$differentRouting]
                        ],
                    ],
                ],
                [...$filter, ...$differentRouting],
            ]
        ];
    }

    /**
     * @test
     */
    public function getIndexInfoByAlias_noAliasesSet_returnsEmptyArray(): void
    {
        $testAlias = 'test';
        $expectedResult = [];

        $actualResult = $this->command->getIndexInfoByAlias($testAlias);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexInfoByAlias_oneIndexIsSetToAlias_returnsDataForThatIndex(): void
    {
        $index = 'alias_test';
        $testAlias = 'test';
        $expectedResult = [
            $index => [
                'aliases' => [
                    $testAlias => [],
                ],
            ],
        ];

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias);
        $actualResult = $this->command->getIndexInfoByAlias($testAlias);
        $this->command->deleteIndex($index);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexInfoByAlias_twoIndexesAreSetToSameAlias_returnsDataForBothIndexes(): void
    {
        $index1 = 'alias_test1';
        $index2 = 'alias_test2';
        $testAlias = 'test';
        $expectedResult = [
            $index1 => [
                'aliases' => [
                    $testAlias => [],
                ],
            ],
            $index2 => [
                'aliases' => [
                    $testAlias => [],
                ],
            ],
        ];

        $this->command->createIndex($index1);
        $this->command->createIndex($index2);
        $this->command->addAlias($index1, $testAlias);
        $this->command->addAlias($index2, $testAlias);
        $actualResult = $this->command->getIndexInfoByAlias($testAlias);
        $this->command->deleteIndex($index1);
        $this->command->deleteIndex($index2);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexesByAlias_noAliasesSet_returnsEmptyArray(): void
    {
        $expectedResult = [];
        $testAlias = 'test';

        $actualResult = $this->command->getIndexesByAlias($testAlias);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexesByAlias_oneIndexIsSetToAlias_returnsArrayWithNameOfThatIndex(): void
    {
        $index = 'alias_test';
        $testAlias = 'test';
        $expectedResult = [$index];

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias);
        $actualResult = $this->command->getIndexesByAlias($testAlias);
        $this->command->deleteIndex($index);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexesByAlias_twoIndexesAreSetToSameAlias_returnsArrayWithNamesForBothIndexes(): void
    {
        $index1 = 'alias_test1';
        $index2 = 'alias_test2';
        $testAlias = 'test';
        $expectedResult = [
            $index1,
            $index2,
        ];

        $this->command->createIndex($index1);
        $this->command->createIndex($index2);
        $this->command->addAlias($index1, $testAlias);
        $this->command->addAlias($index2, $testAlias);
        $actualResult = $this->command->getIndexesByAlias($testAlias);
        $this->command->deleteIndex($index1);
        $this->command->deleteIndex($index2);

        // order is not guaranteed
        sort($expectedResult);
        sort($actualResult);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function getIndexAliases_noAliasesSet_returnsEmptyArray(): void
    {
        $index = 'alias_test';
        $expectedResult = [];

        $actualResult = $this->command->getIndexAliases($index);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     * @todo maybe add more test with alias settings
     */
    public function getIndexAliases_SingleAliasIsSet_returnsDataForThatAlias(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';
        $expectedResult = [
            $testAlias => [],
        ];

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias);
        $actualResult = $this->command->getIndexAliases($index);
        $this->command->deleteIndex($index);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     * @todo maybe add more test with alias settings
     */
    public function getIndexAliases_MultipleAliasesAreSet_returnsDataForThoseAliases(): void
    {
        $index = 'alias_test';
        $testAlias1 = 'test_alias1';
        $testAlias2 = 'test_alias2';
        $expectedResult = [
            $testAlias1 => [],
            $testAlias2 => [],
        ];

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias1);
        $this->command->addAlias($index, $testAlias2);
        $actualResult = $this->command->getIndexAliases($index);
        $this->command->deleteIndex($index);

        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @test
     */
    public function removeAlias_noAliasIsSetForIndex_returnsFalse(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $this->command->createIndex($index);
        $actualResult = $this->command->removeAlias($index, $testAlias);
        $this->command->deleteIndex($index);

        $this->assertFalse($actualResult);
    }

    /**
     * @test
     */
    public function removeAlias_aliasWasSetForIndex_returnsTrue(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $this->command->createIndex($index);
        $this->command->addAlias($index, $testAlias);
        $actualResult = $this->command->removeAlias($index, $testAlias);
        $this->command->deleteIndex($index);

        $this->assertTrue($actualResult);
    }

    /**
     * @test
     */
    public function addAlias_aliasNonExistingIndex_returnsFalse(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $actualResult = $this->command->addAlias($index, $testAlias);

        $this->assertFalse($actualResult);
    }

    /**
     * @test
     */
    public function addAlias_aliasExistingIndex_returnsTrue(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $this->command->createIndex($index);
        $actualResult = $this->command->addAlias($index, $testAlias);
        $this->command->deleteIndex($index);

        $this->assertTrue($actualResult);
    }

    /**
     * @test
     */
    public function aliasActions_makingOperationOverNonExistingIndex_returnsFalse(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $actualResult = $this->command->aliasActions([
            ['add' => ['index' => $index, 'alias' => $testAlias]],
            ['remove' => ['index' => $index, 'alias' => $testAlias]],
        ]);

        $this->assertFalse($actualResult);
    }

    /**
     * @test
     */
    public function aliasActions_makingOperationOverExistingIndex_returnsTrue(): void
    {
        $index = 'alias_test';
        $testAlias = 'test_alias';

        $this->command->createIndex($index);
        $actualResult = $this->command->aliasActions([
            ['add' => ['index' => $index, 'alias' => $testAlias]],
            ['remove' => ['index' => $index, 'alias' => $testAlias]],
        ]);
        $this->command->deleteIndex($index);

        $this->assertTrue($actualResult);
    }

    public function testIndexStats(): void
    {
        $cmd = $this->command;
        if (!$cmd->indexExists('command-test')) {
            $cmd->createIndex('command-test');
        }
        $stats = $cmd->getIndexStats();
        $this->assertArrayHasKey('_all', $stats, print_r(array_keys($stats), true));
        $this->assertArrayHasKey('indices', $stats, print_r(array_keys($stats), true));
        $this->assertArrayHasKey('command-test', $stats['indices'], print_r(array_keys($stats['indices']), true));

        $stats = $cmd->getIndexStats('command-test');
        $this->assertArrayHasKey('_all', $stats, print_r(array_keys($stats), true));
        $this->assertArrayHasKey('indices', $stats, print_r(array_keys($stats), true));
        $this->assertArrayHasKey('command-test', $stats['indices'], print_r(array_keys($stats['indices']), true));
    }
}
