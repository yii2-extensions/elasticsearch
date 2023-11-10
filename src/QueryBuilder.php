<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use stdClass;
use yii\base\BaseObject;
use yii\base\InvalidArgumentException;
use yii\base\NotSupportedException;

use function array_shift;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function reset;
use function strtolower;

/**
 * QueryBuilder builds an Elasticsearch query based on the specification given as a [[Query]] object.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class QueryBuilder extends BaseObject
{
    public function __construct(public Connection $db, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * Generates query from a [[Query]] object.
     *
     * @param Query $query the [[Query]] object from which the query will be generated.
     *
     * @return array the generated SQL statement (the first array element) and the corresponding parameters to be bound
     * to the SQL statement (the second array element).
     *
     * @throws NotSupportedException
     */
    public function build(Query $query): array
    {
        $parts = [];

        if ($query->storedFields !== null) {
            $parts['stored_fields'] = $query->storedFields;
        }

        if ($query->scriptFields !== null) {
            $parts['script_fields'] = $query->scriptFields;
        }

        if ($query->runtimeMappings !== null) {
            $parts['runtime_mappings'] = $query->runtimeMappings;
        }

        if ($query->fields !== null) {
            $parts['fields'] = $query->fields;
        }

        if ($query->source !== null) {
            $parts['_source'] = $query->source;
        }

        if ($query->limit !== null && $query->limit >= 0) {
            $parts['size'] = $query->limit;
        }

        if ($query->offset > 0) {
            $parts['from'] = (int)$query->offset;
        }

        if (isset($query->minScore)) {
            $parts['min_score'] = (float)$query->minScore;
        }

        if (isset($query->explain)) {
            $parts['explain'] = $query->explain;
        }

        // combine a query with where
        $conditionals = [];
        $whereQuery = $this->buildQueryFromWhere($query->where);

        if ($whereQuery) {
            $conditionals[] = $whereQuery;
        }

        if ($query->query) {
            $conditionals[] = $query->query;
        }

        if (count($conditionals) === 2) {
            $parts['query'] = ['bool' => ['must' => $conditionals]];
        } elseif (count($conditionals) === 1) {
            $parts['query'] = reset($conditionals);
        }

        if (!empty($query->highlight)) {
            $parts['highlight'] = $query->highlight;
        }

        if (!empty($query->aggregations)) {
            $parts['aggregations'] = $query->aggregations;
        }

        if (!empty($query->stats)) {
            $parts['stats'] = $query->stats;
        }

        if (!empty($query->suggest)) {
            $parts['suggest'] = $query->suggest;
        }

        if (!empty($query->postFilter)) {
            $parts['post_filter'] = $query->postFilter;
        }

        if (!empty($query->collapse)) {
            $parts['collapse'] = $query->collapse;
        }

        $sort = $this->buildOrderBy($query->orderBy);

        if (!empty($sort)) {
            $parts['sort'] = $sort;
        }

        $options = $query->options;

        if ($query->timeout !== null) {
            $options['timeout'] = $query->timeout;
        }

        return [
            'queryParts' => $parts,
            'index' => $query->index,
            'type' => $query->type,
            'options' => $options,
        ];
    }

    /**
     * Adds order by condition to the query.
     */
    public function buildOrderBy($columns): array
    {
        if (empty($columns)) {
            return [];
        }

        $orders = [];

        foreach ($columns as $name => $direction) {
            if (is_string($direction)) {
                $column = $direction;
                $direction = SORT_ASC;
            } else {
                $column = $name;
            }

            if (($this->db->dslVersion < 7) && $column === '_id') {
                $column = '_uid';
            }

            // allow Elasticsearch extended syntax as described in https://www.elastic.co/guide/en/elasticsearch/guide/master/_sorting.html
            if (is_array($direction)) {
                $orders[] = [$column => $direction];
            } else {
                $orders[] = [$column => ($direction === SORT_DESC ? 'desc' : 'asc')];
            }
        }

        return $orders;
    }

    /**
     * @throws NotSupportedException
     */
    public function buildQueryFromWhere($condition): ?array
    {
        $where = $this->buildCondition($condition);

        if ($where) {
            return [
                'constant_score' => [
                    'filter' => $where,
                ],
            ];
        }

        return null;
    }

    /**
     * Parses the condition specification and generates the corresponding SQL expression.
     *
     * @param array|string|null $condition the condition specification. Please refer to [[Query::where()]] on how to
     * specify a condition.
     *
     * @return array|string the generated SQL expression
     *
     * @throws NotSupportedException if string conditions are used in where
     * @throws InvalidArgumentException if unknown operator is used in a query
     */
    public function buildCondition(array|string $condition = null): array|string
    {
        static $builders = [
            'not' => 'buildNotCondition',
            'and' => 'buildBoolCondition',
            'or' => 'buildBoolCondition',
            'between' => 'buildBetweenCondition',
            'not between' => 'buildBetweenCondition',
            'in' => 'buildInCondition',
            'not in' => 'buildInCondition',
            'like' => 'buildLikeCondition',
            'not like' => 'buildLikeCondition',
            'or like' => 'buildLikeCondition',
            'or not like' => 'buildLikeCondition',
            'lt' => 'buildHalfBoundedRangeCondition',
            '<' => 'buildHalfBoundedRangeCondition',
            'lte' => 'buildHalfBoundedRangeCondition',
            '<=' => 'buildHalfBoundedRangeCondition',
            'gt' => 'buildHalfBoundedRangeCondition',
            '>' => 'buildHalfBoundedRangeCondition',
            'gte' => 'buildHalfBoundedRangeCondition',
            '>=' => 'buildHalfBoundedRangeCondition',
            'match' => 'buildMatchCondition',
            'match_phrase' => 'buildMatchCondition',
        ];

        if (empty($condition)) {
            return [];
        }

        if (!is_array($condition)) {
            throw new NotSupportedException('String conditions in where() are not supported by Elasticsearch.');
        }

        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtolower($condition[0]);
            if (isset($builders[$operator])) {
                $method = $builders[$operator];
                array_shift($condition);

                return $this->$method($operator, $condition);
            }
            throw new InvalidArgumentException('Found unknown operator in query: ' . $operator);
        }

        // hash format: 'column1' => 'value1', 'column2' => 'value2', ...
        return $this->buildHashCondition($condition);
    }

    private function buildHashCondition($condition): array
    {
        $parts = $emptyFields = [];
        foreach ($condition as $attribute => $value) {
            if ($attribute === '_id') {
                if ($value === null) { // there is no null pk
                    $parts[] = ['bool' => ['must_not' => [['match_all' => new stdClass()]]]]; // this condition is equal to WHERE false
                } else {
                    $parts[] = ['ids' => ['values' => is_array($value) ? $value : [$value]]];
                }
            } else if (is_array($value)) { // IN condition
                $parts[] = ['terms' => [$attribute => $value]];
            } else if ($value === null) {
                $emptyFields[] = [ 'exists' => [ 'field' => $attribute ] ];
            } else {
                $parts[] = ['term' => [$attribute => $value]];
            }
        }

        $query = [ 'must' => $parts ];

        if ($emptyFields) {
            $query['must_not'] = $emptyFields;
        }

        return [ 'bool' => $query ];
    }

    /**
     * @throws NotSupportedException
     */
    private function buildNotCondition($operator, $operands): array
    {
        if (count($operands) !== 1) {
            throw new InvalidArgumentException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            $operand = $this->buildCondition($operand);
        }

        return [
            'bool' => [
                'must_not' => $operand,
            ],
        ];
    }

    /**
     * @throws NotSupportedException
     */
    private function buildBoolCondition($operator, $operands): array|null
    {
        $parts = [];
        if ($operator === 'and') {
            $clause = 'must';
        } elseif ($operator === 'or') {
            $clause = 'should';
        } else {
            throw new InvalidArgumentException("Operator should be 'or' or 'and'");
        }

        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand);
            }

            if (!empty($operand)) {
                $parts[] = $operand;
            }
        }

        if ($parts) {
            return [
                'bool' => [
                    $clause => $parts,
                ],
            ];
        }

        return null;
    }

    /**
     * @throws NotSupportedException
     */
    private function buildBetweenCondition($operator, $operands): array
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new InvalidArgumentException("Operator '$operator' requires three operands.");
        }

        [$column, $value1, $value2] = $operands;

        if ($column === '_id') {
            throw new NotSupportedException('Between condition is not supported for the _id field.');
        }

        $filter = ['range' => [$column => ['gte' => $value1, 'lte' => $value2]]];

        if ($operator === 'not between') {
            $filter = ['bool' => ['must_not' => $filter]];
        }

        return $filter;
    }

    /**
     * @throws NotSupportedException
     */
    private function buildInCondition($operator, $operands): array
    {
        if (!isset($operands[0], $operands[1]) || !is_array($operands)) {
            throw new InvalidArgumentException(
                "Operator '$operator' requires array of two operands: column and values"
            );
        }

        [$column, $values] = $operands;

        $values = (array)$values;

        if (empty($values) || $column === []) {
            return $operator === 'in' ? ['bool' => ['must_not' => [['match_all' => new stdClass()]]]] : []; // this condition is equal to WHERE false
        }

        if (is_array($column)) {
            if (count($column) > 1) {
                $this->buildCompositeInCondition($operator, $column, $values);
            }

            $column = reset($column);
        }

        $canBeNull = false;

        foreach ($values as $i => $value) {
            if (is_array($value)) {
                $values[$i] = $value = $value[$column] ?? null;
            }

            if ($value === null) {
                $canBeNull = true;
                unset($values[$i]);
            }
        }

        if ($column === '_id') {
            if (empty($values) && $canBeNull) { // there is no null pk
                $filter = ['bool' => ['must_not' => [['match_all' => new stdClass()]]]]; // this condition is equal to WHERE false
            } else {
                $filter = ['ids' => ['values' => array_values($values)]];
                if ($canBeNull) {
                    $filter = [
                        'bool' => [
                            'should' => [
                                $filter,
                                'bool' => ['must_not' => ['exists' => ['field' => $column]]],
                            ],
                        ],
                    ];
                }
            }
        } else if (empty($values) && $canBeNull) {
            $filter = [
                'bool' => [
                    'must_not' => [
                        'exists' => [ 'field' => $column ],
                    ],
                ],
            ];
        } else {
            $filter = [ 'terms' => [$column => array_values($values)] ];
            if ($canBeNull) {
                $filter = [
                    'bool' => [
                        'should' => [
                            $filter,
                            'bool' => ['must_not' => ['exists' => ['field' => $column]]],
                        ],
                    ],
                ];
            }
        }

        if ($operator === 'not in') {
            $filter = [
                'bool' => [
                    'must_not' => $filter,
                ],
            ];
        }

        return $filter;
    }

    /**
     * Builds a half-bounded range condition (for "gt", ">", "gte", ">=", "lt", "<", "lte", "<=" operators)
     *
     * @param string $operator
     * @param array $operands
     *
     * @return array Filter expression.
     */
    private function buildHalfBoundedRangeCondition(string $operator, array $operands): array
    {
        if (!isset($operands[0], $operands[1])) {
            throw new InvalidArgumentException("Operator '$operator' requires two operands.");
        }

        [$column, $value] = $operands;
        if (($this->db->dslVersion < 7) && $column === '_id') {
            $column = '_uid';
        }

        $range_operator = null;

        if (in_array($operator, ['gte', '>='])) {
            $range_operator = 'gte';
        } elseif (in_array($operator, ['lte', '<='])) {
            $range_operator = 'lte';
        } elseif (in_array($operator, ['gt', '>'])) {
            $range_operator = 'gt';
        } elseif (in_array($operator, ['lt', '<'])) {
            $range_operator = 'lt';
        }

        if ($range_operator === null) {
            throw new InvalidArgumentException("Operator '$operator' is not implemented.");
        }

        return [
            'range' => [
                $column => [
                    $range_operator => $value,
                ],
            ],
        ];
    }

    /**
     * @throws NotSupportedException
     */
    protected function buildCompositeInCondition($operator, $columns, $values): void
    {
        throw new NotSupportedException('composite in is not supported by Elasticsearch.');
    }

    private function buildMatchCondition($operator, $operands): array
    {
        return [
            $operator => [ $operands[0] => $operands[1] ],
        ];
    }
}
