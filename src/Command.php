<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use JsonException;
use stdClass;
use yii\base\Component;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

use function array_filter;
use function array_keys;
use function array_merge;
use function is_array;
use function is_string;
use function json_encode;

/**
 * The Command class implements the API for accessing the Elasticsearch REST API.
 *
 * Check the [Elasticsearch guide](https://www.elastic.co/guide/en/elasticsearch/reference/current/index.html)
 * for details on these commands.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class Command extends Component
{
    public Connection|null $db = null;
    /**
     * @var array|string|null the indexes to execute the query on. Defaults to null meaning all indexes
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-search.html#search-multi-index-type
     */
    public array|string|null $index = null;
    /**
     * @var array|string|null the types to execute the query on. Defaults to null meaning all types
     */
    public string|array|null $type = null;
    /**
     * @var array list of arrays or json strings that become parts of a query
     */
    public array $queryParts = [];
    /**
     * @var array options to be appended to the query URL, such as "search_type" for search or "timeout" for deleting
     */
    public array $options = [];

    /**
     * Sends a request to the _search API and returns the result
     *
     * @param array $options URL options
     *
     *@throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     */
    public function search(array $options = []): mixed
    {
        $query = $this->queryParts;

        if (empty($query)) {
            $query = '{}';
        }

        if (is_array($query)) {
            $query = Json::encode($query);
        }

        $url = [$this->index ?? '_all'];

        if ($this->db->dslVersion < 7 && $this->type !== null) {
            $url[] = $this->type;
        }

        $url[] = '_search';

        return $this->db->get($url, array_merge($this->options, $options), $query);
    }

    /**
     * Sends a request to the deleting by query
     *
     * @param array $options URL options
     *
     *@throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     */
    public function deleteByQuery(array $options = []): mixed
    {
        if (!isset($this->queryParts['query'])) {
            throw new InvalidCallException('Can not call deleteByQuery when no query is given.');
        }

        $query = [
            'query' => $this->queryParts['query'],
        ];

        if (isset($this->queryParts['filter'])) {
            $query['filter'] = $this->queryParts['filter'];
        }

        $query = Json::encode($query);
        $url = [$this->index ?? '_all'];

        if ($this->type !== null) {
            $url[] = $this->type;
        }

        $url[] = '_delete_by_query';

        return $this->db->post($url, array_merge($this->options, $options), $query);
    }

    /**
     * Sends a suggested request to the _search API and returns the result
     *
     * @param array|string $suggester the suggester body
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-suggesters.html
     */
    public function suggest(array|string $suggester, array $options = []): mixed
    {
        if (empty($suggester)) {
            $suggester = '{}';
        }

        if (is_array($suggester)) {
            $suggester = Json::encode($suggester);
        }

        $body = '{"suggest":' . $suggester . ',"size":0}';
        $url = [$this->index ?? '_all', '_search'];
        $result = $this->db->post($url, array_merge($this->options, $options), $body);

        return $result['suggest'];
    }

    /**
     * Inserts a document into an index
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param array|string $data json string or array of data to store
     * @param int|string|null $id the documents' id. If not specified, I'd will be automatically chosen
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-index_.html
     */
    public function insert(
        string $index,
        string|null $type,
        array|string $data,
        string|int $id = null,
        array $options = []
    ): mixed {
        if (empty($data)) {
            $body = '{}';
        } else {
            $body = is_array($data) ? Json::encode($data) : $data;
        }

        if ($id !== null) {
            if ($this->db->dslVersion >= 7) {
                return $this->db->put([$index, '_doc', $id], $options, $body);
            }
            return $this->db->put([$index, $type, $id], $options, $body);
        }

        if ($this->db->dslVersion >= 7) {
            return $this->db->post([$index, '_doc'], $options, $body);
        }

        return $this->db->post([$index, $type], $options, $body);
    }

    /**
     * gets a document from the index
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param int|string|null $id the documents' id.
     * @param array $options URL options
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-get.html
     */
    public function get(string $index, string|null $type, string|int $id = null, array $options = []): mixed
    {
        if ($this->db->dslVersion >= 7) {
            return $this->db->get([$index, '_doc', $id], $options);
        }

        return $this->db->get([$index, $type, $id], $options);
    }

    /**
     * gets multiple documents from the index
     *
     * TODO allow specifying type and index + fields
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param string[] $ids the documents ids as values in an array.
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-multi-get.html
     */
    public function mget(string $index, ?string $type, array $ids, array $options = []): mixed
    {
        $body = Json::encode(['ids' => array_values($ids)]);

        if ($this->db->dslVersion >= 7) {
            return $this->db->get([$index, '_mget'], $options, $body);
        }

        return $this->db->get([$index, $type, '_mget'], $options, $body);
    }

    /**
     * gets a documents _source from the index (>=v0.90.1)
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param string $id the documents' id.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-get.html#_source
     */
    public function getSource(string $index, ?string $type, string $id): mixed
    {
        if ($this->db->dslVersion >= 7) {
            return $this->db->get([$index, '_doc', $id]);
        }

        return $this->db->get([$index, $type, $id]);
    }

    /**
     * gets a document from the index
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param string $id the documents' id.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-get.html
     */
    public function exists(string $index, string|null $type, string $id): mixed
    {
        if ($this->db->dslVersion >= 7) {
            return $this->db->head([$index, '_doc', $id]);
        }

        return $this->db->head([$index, $type, $id]);
    }

    /**
     * deletes a document from the index
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param string $id the documents' id.
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-delete.html
     */
    public function delete(string $index, string|null $type, string $id, array $options = []): mixed
    {
        if ($this->db->dslVersion >= 7) {
            return $this->db->delete([$index, '_doc', $id], $options);
        }

        return $this->db->delete([$index, $type, $id], $options);
    }

    /**
     * updates a document
     *
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param string $id the documents' id.
     * @param mixed $data the documents' data.
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-update.html
     */
    public function update(string $index, string|null $type, string $id, mixed $data, array $options = []): mixed
    {
        $body = ['doc' => empty($data) ? new stdClass() : $data];

        if (isset($options['detect_noop'])) {
            $body['detect_noop'] = $options['detect_noop'];
            unset($options['detect_noop']);
        }

        if ($this->db->dslVersion >= 7) {
            return $this->db->post([$index, '_update', $id], $options, Json::encode($body));
        }

        return $this->db->post([$index, $type, $id, '_update'], $options, Json::encode($body));
    }

    // TODO bulk https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html

    /**
     * creates an index
     *
     * @param string $index Index that the document belongs to.
     * @param array|null $configuration
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-create-index.html
     */
    public function createIndex(string $index, array $configuration = null): mixed
    {
        $body = $configuration !== null ? Json::encode($configuration) : null;

        return $this->db->put([$index], [], $body);
    }

    /**
     * deletes an index
     *
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-delete-index.html
     */
    public function deleteIndex(string $index): mixed
    {
        return $this->db->delete([$index]);
    }

    /**
     * deletes all indexes
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-delete-index.html
     */
    public function deleteAllIndexes(): mixed
    {
        return $this->db->delete(['_all']);
    }

    /**
     * checks whether an index exists
     *
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-exists.html
     */
    public function indexExists(string $index): mixed
    {
        return $this->db->head([$index]);
    }

    /**
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-types-exists.html
     */
    public function typeExists(string $index, string|null $type): mixed
    {
        if ($this->db->dslVersion >= 7) {
            return $this->db->head([$index, '_doc']);
        }

        return $this->db->head([$index, $type]);
    }

    /**
     * @param string $alias
     *
     *@throws InvalidConfigException
     * @throws Exception
     *
     * @return bool
     */
    public function aliasExists(string $alias): bool
    {
        $indexes = $this->getIndexesByAlias($alias);

        return !empty($indexes);
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return array
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#alias-retrieving
     */
    public function getAliasInfo(): array
    {
        $aliasInfo = $this->db->get(['_alias', '*']);

        return $aliasInfo ?: [];
    }

    /**
     * @param string $alias
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return array
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#alias-retrieving
     */
    public function getIndexInfoByAlias(string $alias): array
    {
        $responseData = $this->db->get(['_alias', $alias]);

        if (empty($responseData)) {
            return [];
        }

        return $responseData;
    }

    /**
     * @param string $alias
     *
     *@throws InvalidConfigException
     * @throws Exception
     *
     * @return array
     */
    public function getIndexesByAlias(string $alias): array
    {
        return array_keys($this->getIndexInfoByAlias($alias));
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return array
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#alias-retrieving
     */
    public function getIndexAliases(string $index): array
    {
        $responseData = $this->db->get([$index, '_alias', '*']);

        if (empty($responseData)) {
            return [];
        }

        return $responseData[$index]['aliases'];
    }

    /**
     * @param string $index Index that the document belongs to.
     * @param string $alias
     * @param array $aliasParameters
     *
     * @throws InvalidConfigException
     * @throws Exception
     * @throws JsonException
     *
     * @return bool
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#alias-adding
     */
    public function addAlias(string $index, string $alias, array $aliasParameters = []): bool
    {
        return (bool) $this->db->put(
            [$index, '_alias', $alias],
            [],
            json_encode((object) $aliasParameters, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param string $index Index that the document belongs to.
     * @param string $alias
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return bool
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#deleting
     */
    public function removeAlias(string $index, string $alias): bool
    {
        return (bool) $this->db->delete([$index, '_alias', $alias]);
    }

    /**
     * Runs alias manipulations.
     * If you want to add alias1 to index1
     * and remove alias2 from index2 you can use the following commands:
     * ~~~
     * $actions = [
     *      ['add' => ['index' => 'index1', 'alias' => 'alias1']],
     *      ['remove' => ['index' => 'index2', 'alias' => 'alias2']],
     * ];
     * ~~~
     *
     * @param array $actions
     *
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws Exception
     *
     * @return bool
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/2.0/indices-aliases.html#indices-aliases
     */
    public function aliasActions(array $actions): bool
    {
        return (bool) $this->db->post(['_aliases'], [], json_encode(['actions' => $actions], JSON_THROW_ON_ERROR));
    }

    /**
     * Change specific index level settings in real time.
     * Note that update analyzers required to [[close()]] the index first and [[open()]] it after the changes are made,
     * use [[updateAnalyzers()]] for it.
     *
     * @param string $index Index that the document belongs to.
     * @param array|string|null $setting
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elasticsearch.org/guide/en/elasticsearch/reference/current/indices-update-settings.html
     */
    public function updateSettings(string $index, array|string $setting = null, array $options = []): mixed
    {
        if ($setting !== null) {
            $body = is_string($setting) ? $setting : Json::encode($setting);
        } else {
            $body = null;
        }

        return $this->db->put([$index, '_settings'], $options, $body);
    }

    /**
     * Define new analyzers for the index.
     * For example, if content analyzer hasn’t been defined on "myindex" yet
     * you can use the following commands to add it:
     *
     * ~~~
     *  $setting = [
     *      'analysis' => [
     *          'analyzer' => [
     *              'ngram_analyzer_with_filter' => [
     *                  'tokenizer' => 'ngram_tokenizer',
     *                  'filter' => 'lowercase, snowball'
     *              ],
     *          ],
     *          'tokenizer' => [
     *              'ngram_tokenizer' => [
     *                  'type' => 'nGram',
     *                  'min_gram' => 3,
     *                  'max_gram' => 10,
     *                  'token_chars' => ['letter', 'digit', 'whitespace', 'punctuation', 'symbol']
     *              ],
     *          ],
     *      ]
     * ];
     * $elasticQuery->createCommand()->updateAnalyzers('myindex', $setting);
     * ~~~
     *
     * @param string $index Index that the document belongs to.
     * @param array|string $setting
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-update-settings.html#update-settings-analysis
     */
    public function updateAnalyzers(string $index, array|string $setting, array $options = []): mixed
    {
        $this->closeIndex($index);
        $result = $this->updateSettings($index, $setting, $options);
        $this->openIndex($index);

        return $result;
    }

    // TODO https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-get-settings.html

    // TODO https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-warmers.html

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-open-close.html
     */
    public function openIndex(string $index): mixed
    {
        return $this->db->post([$index, '_open']);
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-open-close.html
     */
    public function closeIndex(string $index): mixed
    {
        return $this->db->post([$index, '_close']);
    }

    /**
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-scroll.html
     */
    public function scroll(array $options = []): mixed
    {
        $body = array_filter(
            [
                'scroll' => ArrayHelper::remove($options, 'scroll'),
                'scroll_id' => ArrayHelper::remove($options, 'scroll_id'),
            ],
        );

        if (empty($body)) {
            $body = (object) [];
        }

        return $this->db->post(['_search', 'scroll'], $options, Json::encode($body));
    }

    /**
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-scroll.html
     */
    public function clearScroll(array $options = []): mixed
    {
        $body = array_filter(
            [
                'scroll_id' => ArrayHelper::remove($options, 'scroll_id'),
            ],
        );

        if (empty($body)) {
            $body = (object) [];
        }

        return $this->db->delete(['_search', 'scroll'], $options, Json::encode($body));
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-stats.html
     */
    public function getIndexStats(string $index = '_all'): mixed
    {
        return $this->db->get([$index, '_stats']);
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-recovery.html
     */
    public function getIndexRecoveryStats(string $index = '_all'): mixed
    {
        return $this->db->get([$index, '_recovery']);
    }

    // https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-segments.html

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-clearcache.html
     */
    public function clearIndexCache(string $index): mixed
    {
        return $this->db->post([$index, '_cache', 'clear']);
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-flush.html
     */
    public function flushIndex(string $index = '_all'): mixed
    {
        return $this->db->post([$index, '_flush']);
    }

    /**
     * @param string $index Index that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-refresh.html
     */
    public function refreshIndex(string $index): mixed
    {
        return $this->db->post([$index, '_refresh']);
    }

    // TODO https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-optimize.html
    // TODO https://www.elastic.co/guide/en/elasticsearch/reference/0.90/indices-gateway-snapshot.html

    /**
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     * @param array|string|null $mapping
     * @param array $options URL options
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-put-mapping.html
     */
    public function setMapping(string $index, string|null $type, array|string|null $mapping, array $options = []): mixed
    {
        if ($mapping !== null) {
            $body = is_string($mapping) ? $mapping : Json::encode($mapping);
        } else {
            $body = null;
        }

        if ($this->db->dslVersion >= 7) {
            $endpoint = [$index, '_mapping'];
        } else {
            $endpoint = [$index, '_mapping', $type];
        }

        return $this->db->put($endpoint, $options, $body);
    }

    /**
     * @param string $index Index that the document belongs to.
     * @param string|null $type Type that the document belongs to.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-get-mapping.html
     */
    public function getMapping(string $index = '_all', string $type = null): mixed
    {
        $url = [$index, '_mapping'];

        if ($this->db->dslVersion < 7 && $type !== null) {
            $url[] = $type;
        }

        return $this->db->get($url);
    }

    /**
     * @param string $index Index that the document belongs to.
     * @param string $type
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-get-field-mapping.html
     */
//    public function getFieldMapping($index, $type = '_all')
//    {
    //		// TODO implement
//        return $this->db->put([$index, $type, '_mapping']);
//    }

    /**
     * @param $options
     * @param string $index Index that the document belongs to.
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-analyze.html
     */
    //	public function analyze($options, $index = null)
    //	{
    //		// TODO implement
    ////		return $this->db->put([$index]);
    //	}

    /**
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-templates.html
     */
    public function createTemplate(
        string $name,
        string $pattern,
        string $settings,
        array|string|null $mappings,
        int $order = 0
    ): mixed {
        $body = Json::encode([
            'template' => $pattern,
            'order' => $order,
            'settings' => (object) $settings,
            'mappings' => (object) $mappings,
        ]);

        return $this->db->put(['_template', $name], [], $body);
    }

    /**
     * @param $name
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-templates.html
     */
    public function deleteTemplate($name): mixed
    {
        return $this->db->delete(['_template', $name]);
    }

    /**
     * @param $name
     *
     * @throws Exception
     * @throws InvalidConfigException
     *
     * @return mixed
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/indices-templates.html
     */
    public function getTemplate($name): mixed
    {
        return $this->db->get(['_template', $name]);
    }
}
