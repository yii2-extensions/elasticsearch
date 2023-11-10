<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\QueryInterface;
use yii\db\QueryTrait;

use function array_merge;
use function count;
use function func_get_args;
use function is_array;
use function is_string;
use function reset;

/**
 * Query represents a query to the search API of Elasticsearch.
 *
 * Query provides a set of methods to facilitate the specification of different parameters of the query.
 * These methods can be chained together.
 *
 * By calling [[createCommand()]], we can get a [[Command]] instance which can
 * be further used to perform/execute the DB query against a database.
 *
 * For example,
 *
 * ~~~
 * $query = new Query;
 * $query->storedFields('id, name')
 *     ->from('myindex', 'users')
 *     ->limit(10);
 * // build and execute the query
 * $command = $query->createCommand();
 * $rows = $command->search(); // this way you get the raw output of Elasticsearch.
 * ~~~
 *
 * You would normally call `$query->search()` instead of creating a command as
 * this method adds the `indexBy()` feature and also removes some
 * inconsistencies from the response.
 *
 * Query also provides some methods to easier get some parts of the result only:
 *
 * - [[one()]]: returns a single record populated with the first row of data.
 * - [[all()]]: returns all records based on the query results.
 * - [[count()]]: returns the number of records.
 * - [[scalar()]]: returns the value of the first column in the first row of the query result.
 * - [[column()]]: returns the value of the first column in the query result.
 * - [[exists()]]: returns a value indicating whether the query result has data or not.
 *
 * NOTE: Elasticsearch limits the number of records returned to 10 records by default.
 *
 * If you expect to get more records, you should specify the limit explicitly.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class Query extends Component implements QueryInterface
{
    use QueryTrait;

    /**
     * @var array|null the fields being retrieved from the documents. For example, `['id', 'name']`.
     *
     * If not set, this option will not be applied to the query and no fields will be returned.
     * In this case, the `_source` field will be returned by default which can be configured using [[source]].
     * Setting this to an empty array will result in no fields being retrieved, which means that only the primaryKey of
     * a record will be available in the result.
     *
     * > Note: Field values are [always returned as arrays] even if they only have one value.
     *
     * [always returned as arrays]: https://www.elastic.co/guide/en/elasticsearch/reference/current/array.html
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-stored-fields.html
     * @see storedFields()
     * @see source
     */
    public array|null $storedFields = null;
    /**
     * @var array|null the scripted fields being retrieved from the documents.
     *
     * Example:
     * ```php
     * $query->scriptFields = [
     *     'value_times_two' => [
     *         'script' => "doc['my_field_name'].value * 2",
     *     ],
     *     'value_times_factor' => [
     *         'script' => "doc['my_field_name'].value * factor",
     *         'params' => [
     *             'factor' => 2.0
     *         ],
     *     ],
     * ]
     * ```
     *
     * > Note: Field values are [always returned as arrays] even if they only have one value.
     *
     * [always returned as arrays]: https://www.elastic.co/guide/en/elasticsearch/reference/current/array.html
     * [script field]: https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-script-fields.html
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-script-fields.html
     * @see scriptFields()
     * @see source
     */
    public array|null $scriptFields = null;
    /**
     * @var array|null An array of runtime fields evaluated at query time
     *
     * Example:
     * ```php
     * $query->$runtimeMappings = [
     *     'value_times_two' => [
     *         'type' => 'double',
     *         'script' => "emit(doc['my_field_name'].value * 2)",
     *     ],
     *     'value_times_factor' => [
     *         'type' => 'double',
     *         'script' => "emit(doc['my_field_name'].value * factor)",
     *         'params' => [
     *             'factor' => 2.0
     *         ],
     *     ],
     * ]
     * ```
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/runtime-mapping-fields.html
     * @see runtimeMappings()
     * @see source
     */
    public array|null $runtimeMappings = null;
    /**
     * @var array|null Use the field parameter to retrieve the values of runtime fields. Runtime fields won't be
     * displayed in _source, but the fields API work for all fields, even those that were not sent as part of the
     * original _source.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/runtime-retrieving-fields.html
     * @see fields()
     * @see fields
     */
    public array|null $fields = null;
    /**
     * @var array|bool|null this option controls how the `_source` field is returned from the documents.
     * For example, `['id', 'name']` means that only the `id` and `name` field should be returned from `_source`.
     * If not set, it means retrieving the full `_source` field unless [[fields]] are  specified.
     * Setting this option to `false` will disable return of the `_source` field, this means that only the primaryKey of
     * a record will be available in the result.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-source-filtering.html
     * @see source()
     * @see fields
     */
    public array|bool|null $source = null;
    /**
     * @var array|string|null The index to retrieve data from. This can be a string representing a single index or an
     * array of multiple indexes.
     * If this is not set, indexes are being queried.
     *
     * @see from()
     */
    public string|array|null $index = null;
    /**
     * @var array|string|null The type to retrieve data from. This can be a string representing a single type or an
     * array of multiple types.
     * If this is not set, all types are being queried.
     *
     * @see from()
     */
    public string|array|null $type = null;
    /**
     * @var int|null A search timeout, bounding the search request to be executed within the specified time value and
     * bail with the hits accumulated up to that point when expired.
     *
     * Defaults to no timeout.
     *
     * @see timeout()
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search.html#global-search-timeout
     */
    public int|null $timeout = null;
    /**
     * @var array|string The query part of this search query. This is an array or json string that follows the format of
     * the elasticsearch.
     * [Query DSL](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl.html).
     */
    public string|array $query = [];
    /**
     * @var array|string The filter part of this search query. This is an array or json string that follows the format
     * of the elasticsearch.
     * [Query DSL](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl.html).
     */
    public string|array $filter = [];
    /**
     * @var array|string The `post_filter` part of the search query for differential filter search results and
     * aggregations.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/guide/current/_post_filter.html
     */
    public string|array $postFilter = [];
    /**
     * @var array The highlight part of this search query. This is an array that allows to highlight search results
     * on one or more fields.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-highlighting.html
     */
    public array $highlight = [];
    /**
     * @var array List of aggregations to add to this query.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-aggregations.html
     */
    public array $aggregations = [];
    /**
     * @var array the 'stats' part of the query. An array of groups to maintain a statistics aggregation for.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search.html#stats-groups
     */
    public array $stats = [];
    /**
     * @var array list of suggesters to add to this query.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters.html
     */
    public array $suggest = [];
    /**
     * @var array list of collapse to add to this query.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters.html
     */
    public array $collapse = [];
    /**
     * @var float|null Exclude documents which have a _score less than the minimum specified in min_score
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-min-score.html
     */
    public float|null $minScore = null;
    /**
     * @var array list of options that will be passed to commands created by this query.
     *
     * @see Command::$options
     */
    public array $options = [];
    /**
     * @var bool Enables explanation for each hit on how its score was computed.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-explain.html
     */
    public bool $explain = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        // setting the default limit according to Elasticsearch defaults
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-body.html#_parameters_5
        if ($this->limit === null) {
            $this->limit = 10;
        }
    }

    /**
     * Creates a DB command that can be used to execute this query.
     *
     * @param Connection|null $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application
     * component will be used.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return Command the created DB command instance.
     */
    public function createCommand(Connection $db = null): Command
    {
        if ($db === null) {
            $db = Yii::$app->get('elasticsearch');
        }

        $commandConfig = $db->getQueryBuilder()->build($this);

        return $db->createCommand($commandConfig);
    }

    /**
     * Executes the query and returns all results as an array.
     *
     * @param Connection $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return array the query results. If the query results in nothing, an empty array will be returned.
     */
    public function all($db = null): array
    {
        if ($this->emulateExecution) {
            return [];
        }

        $result = $this->createCommand($db)->search();

        if ($result === false) {
            throw new Exception('Elasticsearch search query failed.');
        }

        if (empty($result['hits']['hits'])) {
            return [];
        }

        $rows = $result['hits']['hits'];

        return $this->populate($rows);
    }

    /**
     * Converts the raw query results into the format as specified by this query.
     * This method is internally used to convert the data fetched from a database into the format as required by this
     * query.
     *
     * @param array $rows the raw query result from a database.
     *
     * @return array the converted query result.
     */
    public function populate(array $rows): array
    {
        if ($this->indexBy === null) {
            return $rows;
        }

        $models = [];

        foreach ($rows as $key => $row) {
            if ($this->indexBy !== null) {
                if (is_string($this->indexBy)) {
                    $key = isset($row['fields'][$this->indexBy]) ?
                        reset($row['fields'][$this->indexBy]) : $row['_source'][$this->indexBy];
                } else {
                    $key = ($this->indexBy)($row);
                }
            }

            $models[$key] = $row;
        }

        return $models;
    }

    /**
     * Executes the query and returns a single row of a result.
     *
     * @param Connection $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return ActiveRecord|array|bool|null the first row (in terms of an array) of the query result.
     * False is returned if the query results in nothing.
     */
    public function one($db = null)
    {
        if ($this->emulateExecution) {
            return false;
        }

        $result = $this->createCommand($db)->search(['size' => 1]);

        if ($result === false) {
            throw new Exception('Elasticsearch search query failed.');
        }

        if (empty($result['hits']['hits'])) {
            return false;
        }

        return reset($result['hits']['hits']);
    }

    /**
     * Executes the query and returns the complete search result including e.g. hits, aggregations, suggesters,
     * totalCount.
     *
     * @param Connection|null $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     * @param array $options The options given with this query.
     * Possible options are:
     *
     *  - [routing](https://www.elastic.co/guide/en/elasticsearch/reference/current/search.html#search-routing)
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return array the query results.
     */
    public function search(Connection $db = null, array $options = [])
    {
        if ($this->emulateExecution) {
            return [
                'hits' => [
                    'total' => 0,
                    'hits' => [],
                ],
            ];
        }

        $result = $this->createCommand($db)->search($options);

        if ($result === false) {
            throw new Exception('Elasticsearch search query failed.');
        }

        if (!empty($result['hits']['hits']) && $this->indexBy !== null) {
            $rows = [];
            foreach ($result['hits']['hits'] as $row) {
                if (is_string($this->indexBy)) {
                    $key = $row['fields'][$this->indexBy] ?? $row['_source'][$this->indexBy];
                } else {
                    $key = ($this->indexBy)($row);
                }
                $rows[$key] = $row;
            }
            $result['hits']['hits'] = $rows;
        }

        return $result;
    }

    /**
     * Executes the query and deletes all matching documents.
     *
     * Everything except query and filter will be ignored.
     *
     * @param Connection|null $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     * @param array $options The options given with this query.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return array the query results.
     */
    public function delete(Connection $db = null, array $options = []): array
    {
        if ($this->emulateExecution) {
            return [];
        }

        return $this->createCommand($db)->deleteByQuery($options);
    }

    /**
     * Returns the query results as a scalar value.
     *
     * The value returned will be the specified field in the first document of the query results.
     *
     * @param string $field name of the attribute to select.
     * @param Connection|null $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return string|null the value of the specified attribute in the first record of the query result.
     * Null is returned if the query result is empty or the field does not exist.
     */
    public function scalar(string $field, Connection $db = null): string|null
    {
        if ($this->emulateExecution) {
            return null;
        }

        $record = self::one($db);

        if ($record !== false) {
            if ($field === '_id') {
                return $record['_id'];
            }

            if (isset($record['_source'][$field])) {
                return $record['_source'][$field];
            }

            if (isset($record['fields'][$field])) {
                return count($record['fields'][$field]) === 1
                    ? reset($record['fields'][$field])
                    : $record['fields'][$field];
            }
        }

        return null;
    }

    /**
     * Executes the query and returns the first column of the result.
     *
     * @param string $field the field to query over.
     * @param Connection|null $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return array the first column of the query result. An empty array is returned if the query results in nothing.
     */
    public function column(string $field, Connection $db = null): array
    {
        if ($this->emulateExecution) {
            return [];
        }

        $command = $this->createCommand($db);
        $command->queryParts['_source'] = [$field];
        $result = $command->search();

        if ($result === false) {
            throw new Exception('Elasticsearch search query failed.');
        }

        if (empty($result['hits']['hits'])) {
            return [];
        }

        $column = [];

        foreach ($result['hits']['hits'] as $row) {
            if (isset($row['fields'][$field])) {
                $column[] = $row['fields'][$field];
            } elseif (isset($row['_source'][$field])) {
                $column[] = $row['_source'][$field];
            } else {
                $column[] = null;
            }
        }

        return $column;
    }

    /**
     * Returns the number of records.
     *
     * @param string $q the COUNT expression. This parameter is ignored by this implementation.
     * @param Connection $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return int number of records.
     */
    public function count($q = '*', $db = null): int
    {
        if ($this->emulateExecution) {
            return 0;
        }

        $command = $this->createCommand($db);

        // performing a query with return size of 0, is equal to getting result stats such as count
        // https://www.elastic.co/guide/en/elasticsearch/reference/5.6/breaking_50_search_changes.html#_literal_search_type_literal
        $searchOptions = ['size' => 0];

        // Set track_total_hits to 'true' for ElasticSearch version 6 and up
        // https://www.elastic.co/guide/en/elasticsearch/reference/master/search-your-data.html#track-total-hits
        if ($command->db->dslVersion >= 6) {
            $searchOptions['track_total_hits'] = 'true';
        }

        $result = $command->search($searchOptions);

        // since ES7 totals are returned as an array (with count and precision values)
        if (isset($result['hits']['total'])) {
            return is_array($result['hits']['total']) ? (int)$result['hits']['total']['value'] : (int)$result['hits']['total'];
        }

        return 0;
    }

    /**
     * Returns a value indicating whether the query result contains any row of data.
     *
     * @param Connection $db the database connection used to execute the query.
     * If this parameter is not given, the `elasticsearch` application component will be used.
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return bool whether the query result contains any row of data.
     */
    public function exists($db = null): bool
    {
        return self::one($db) !== false;
    }

    /**
     * Adds a 'stats' part to the query.
     *
     * @param array $groups an array of groups to maintain a statistics aggregation for.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search.html#stats-groups
     */
    public function stats(array $groups): static
    {
        $this->stats = $groups;

        return $this;
    }

    /**
     * Sets a highlight parameters to retrieve from the documents.
     *
     * @param array $highlight array of parameters to highlight results.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-highlighting.html
     */
    public function highlight(array $highlight): static
    {
        $this->highlight = $highlight;

        return $this;
    }

    /**
     * Adds an aggregation to this query. Supports nested aggregations.
     *
     * @param string $name the name of the aggregation.
     * @param array|string $options the configuration options for this aggregation. Can be an array or a json string.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.3/search-aggregations.html
     */
    public function addAggregate(string $name, array|string $options): static
    {
        $this->aggregations[$name] = $options;

        return $this;
    }

    /**
     * Adds a suggester to this query.
     *
     * @param string $name the name of the suggester.
     * @param array|string $definition the configuration options for this suggester.
     * Can be an array or a json string.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters.html
     */
    public function addSuggester(string $name, array|string $definition): static
    {
        $this->suggest[$name] = $definition;

        return $this;
    }

    /**
     * Adds a collapse to this query.
     *
     * @param array $collapse the configuration options for collapse.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/5.3/search-request-collapse.html#search-request-collapse
     */
    public function addCollapse(array $collapse): static
    {
        $this->collapse = $collapse;

        return $this;
    }

    // TODO add validate query https://www.elastic.co/guide/en/elasticsearch/reference/current/search-validate.html
    // TODO support multi query via static method https://www.elastic.co/guide/en/elasticsearch/reference/current/search-multi-search.html

    /**
     * Sets the query part of this search query.
     *
     * @param array|string $query the query to be set.
     *
     * @return static the query object itself.
     */
    public function query(array|string $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Starts a batch query.
     *
     * A batch query supports fetching data in batches, which can keep the memory usage under a limit.
     * This method will return a [[BatchQueryResult]] object which implements the [[\Iterator]] interface and can be
     * traversed to retrieve the data in batches.
     *
     * For example,
     *
     * ```php
     * $query = (new Query)->from('user');
     * foreach ($query->batch() as $rows) {
     *     // $rows is an array of 10 or fewer rows from user table
     * }
     * ```
     *
     * Batch size is determined by the `limit` setting (note that in scan mode batch limit is per shard).
     *
     * @param string $scrollWindow how long Elasticsearch should keep the search context alive, in
     * [time units](https://www.elastic.co/guide/en/elasticsearch/reference/current/common-options.html#time-units)
     * @param Connection|null $db the database connection. If not set, the `elasticsearch` application component will be
     * used.
     *
     * @throws InvalidConfigException
     *
     * @return BatchQueryResult the batch query result. It implements the [[\Iterator]] interface and can be traversed
     * to retrieve the data in batches.
     */
    public function batch(string $scrollWindow = '1m', Connection $db = null): BatchQueryResult
    {
        return Yii::createObject([
            'class' => BatchQueryResult::className(),
            'query' => $this,
            'scrollWindow' => $scrollWindow,
            'db' => $db,
            'each' => false,
        ]);
    }

    /**
     * Starts a batch query and retrieves data row by row.
     *
     * This method is similar to [[batch()]] except that in each iteration of the result, only one row of data is
     * returned.
     *
     * For example,
     *
     * ```php
     * $query = (new Query)->from('user');
     * foreach ($query->each() as $row) {
     * }
     * ```
     *
     * @param string $scrollWindow how long Elasticsearch should keep the search context alive, in
     * [time units](https://www.elastic.co/guide/en/elasticsearch/reference/current/common-options.html#time-units)
     * @param Connection|null $db the database connection. If not set, the `elasticsearch` application component will be
     * used.
     *
     * @throws InvalidConfigException
     *
     * @return BatchQueryResult the batch query result. It implements the [[\Iterator]] interface and can be traversed
     * to retrieve the data in batches.
     */
    public function each(string $scrollWindow = '1m', Connection $db = null): BatchQueryResult
    {
        return Yii::createObject([
            'class' => BatchQueryResult::className(),
            'query' => $this,
            'scrollWindow' => $scrollWindow,
            'db' => $db,
            'each' => true,
        ]);
    }

    /**
     * Sets the index and type to retrieve documents from.
     *
     * @param array|string $index The index to retrieve data from. This can be a string representing a single index or a
     * an array of multiple indexes.
     * If this is `null`, it means that all indexes are being queried.
     * @param array|string|null $type The type to retrieve data from. This can be a string representing a single type or
     * an array of multiple types.
     * If this is `null`, it means that all types are being queried.
     *
     * @return $this the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-search.html#search-multi-index-type
     */
    public function from(array|string $index, array|string $type = null): static
    {
        $this->index = $index;
        $this->type = $type;

        return $this;
    }

    /**
     * Sets the fields to retrieve from the documents.
     *
     * Quote from the Elasticsearch doc:
     * > The stored_fields parameter is about fields that are explicitly marked
     * > as stored in the mapping, which is off by default and generally not
     * > recommended. Use source filtering instead to select subsets of the
     * > original source document to be returned.
     *
     * @param array|string|null $fields the fields to be selected.
     *
     * @return static the query object itself
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-stored-fields.html
     */
    public function storedFields(array|string|null $fields): static
    {
        match (is_array($fields) || $fields === null) {
            true => $this->storedFields = $fields,
            default => $this->storedFields = func_get_args(),
        };

        return $this;
    }

    /**
     * Sets the script fields to retrieve from the documents.
     *
     * @param array|string|null $fields the fields to be selected.
     *
     * @return static the query object itself
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-script-fields.html
     */
    public function scriptFields(array|string|null $fields): static
    {
        match (is_array($fields) || $fields === null) {
            true => $this->scriptFields = $fields,
            default => $this->scriptFields = func_get_args(),
        };

        return $this;
    }

    /**
     * Sets the runtime mappings for this query
     *
     * @param array|string|null $mappings the mappings to be set.
     *
     * @return static the query object itself
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/runtime.html
     */
    public function runtimeMappings(array|string|null $mappings): static
    {
        match (is_array($mappings) || $mappings === null) {
            true => $this->runtimeMappings = $mappings,
            default => $this->runtimeMappings = func_get_args(),
        };

        return $this;
    }

    /**
     * Sets the runtime fields to retrieve from the documents.
     *
     * @param array|string|null $fields the fields to be selected.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/runtime-retrieving-fields.html
     */
    public function fields(array|string|null $fields): static
    {
        match (is_array($fields) || $fields === null) {
            true => $this->fields = $fields,
            default => $this->fields = func_get_args(),
        };

        return $this;
    }

    /**
     * Sets the source filtering, specifying how the `_source` field of the document should be returned.
     *
     * @param array|bool|string|null $source the source patterns to be selected.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-source-filtering.html
     */
    public function source(bool|array|string|null $source): static
    {
        match (is_array($source) || $source === null  || $source === false) {
            true => $this->source = $source,
            default => $this->source = func_get_args(),
        };

        return $this;
    }

    /**
     * Sets the search timeout.
     *
     * @param int $timeout A search timeout, bounding the search request to be executed within the specified time value
     * and bail with the hits accumulated up to that point when it expired.
     *
     * Defaults to no timeout.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-body.html#_parameters_5
     */
    public function timeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * @param float $minScore Exclude documents which have a `_score` less than the minimum specified minScore.
     *
     * @return static the query object itself.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-min-score.html
     */
    public function minScore(float $minScore): static
    {
        $this->minScore = $minScore;

        return $this;
    }

    /**
     * Sets the options to be passed to the command created by this query.
     *
     * @param array $options the options to be set.
     *
     * @throws InvalidArgumentException if $options is not an array.
     *
     * @return static the query object itself.
     *
     * @see Command::$options
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Adds more options, overwriting existing options.
     *
     * @param array $options the options to be added.
     *
     * @throws InvalidArgumentException if $options is not an array.
     *
     * @return static the query object itself.
     *
     * @see options()
     */
    public function addOptions(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function andWhere($condition): QueryInterface
    {
        if ($this->where === null) {
            $this->where = $condition;
        } elseif (isset($this->where[0]) && $this->where[0] === 'and') {
            $this->where[] = $condition;
        } else {
            $this->where = ['and', $this->where, $condition];
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function orWhere($condition): QueryInterface
    {
        if ($this->where === null) {
            $this->where = $condition;
        } elseif (isset($this->where[0]) && $this->where[0] === 'or') {
            $this->where[] = $condition;
        } else {
            $this->where = ['or', $this->where, $condition];
        }

        return $this;
    }

    /**
     * Set the `post_filter` part of the search query.
     *
     * @param array|string $filter the filter to be set.
     *
     * @return static the query object itself.
     *
     * @see $postFilter
     */
    public function postFilter(array|string $filter): static
    {
        $this->postFilter = $filter;

        return $this;
    }

    /**
     * Explain for how the score of each document was computer.
     *
     * @param bool $explain whether to explain the score computation for each hit or not.
     *
     * @return static the query object itself.
     *
     * @see $explain
     */
    public function explain(bool $explain): static
    {
        $this->explain = $explain;
        return $this;
    }
}
