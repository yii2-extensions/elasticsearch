<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\db\ActiveQueryInterface;

use function array_keys;
use function is_array;
use function is_string;

/**
 * ActiveDataProvider is an enhanced version of [[\yii\data\ActiveDataProvider]] specific to the Elasticsearch.
 * It allows fetching not only rows and total rows count, but full query results including aggregations and so on.
 *
 * Note: this data provider fetches result models and total count using a single Elasticsearch query, so result total
 * count will be fetched after pagination limit applying, which eliminates the ability to verify if the requested page
 * number actually exists.
 *
 * Data provider disables [[yii\data\Pagination::$validatePage]] automatically because of this.
 *
 * @property array $aggregations All aggregations result.
 * @property array $queryResults Full query results.
 * @property array $suggestions All suggestions result.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 */
class ActiveDataProvider extends \yii\data\ActiveDataProvider
{
    /**
     * @var array|null the full query results.
     */
    private array|null $_queryResults = null;

    /**
     * @param array $results full query results
     */
    public function setQueryResults(array $results): void
    {
        $this->_queryResults = $results;
    }

    /**
     * @return array|null full query results
     */
    public function getQueryResults(): array|null
    {
        if (!is_array($this->_queryResults)) {
            $this->prepare();
        }

        return $this->_queryResults;
    }

    /**
     * @return array all aggregations result
     */
    public function getAggregations(): array
    {
        $results = $this->getQueryResults();

        return $results['aggregations'] ?? [];
    }

    /**
     * Returns results of the specified aggregation.
     *
     * @param string $name aggregation name.
     *
     * @return array aggregation results.
     *
     * @throws InvalidCallException if query results do not contain the requested aggregation.
     */
    public function getAggregation(string $name): array
    {
        $aggregations = $this->getAggregations();

        if (!isset($aggregations[$name])) {
            throw new InvalidCallException("Aggregation '$name' not found.");
        }

        return $aggregations[$name];
    }

    /**
     * @return array all suggestions result
     */
    public function getSuggestions(): array
    {
        $results = $this->getQueryResults();

        return $results['suggest'] ?? [];
    }

    /**
     * Returns results of the specified suggestion.
     *
     * @param string $name suggestion name.
     *
     * @return array suggestion results.
     *
     * @throws InvalidCallException if query results do not contain the requested suggestion.
     */
    public function getSuggestion(string $name): array
    {
        $suggestions = $this->getSuggestions();

        if (!isset($suggestions[$name])) {
            throw new InvalidCallException("Suggestion '$name' not found.");
        }

        return $suggestions[$name];
    }

    /**
     * {@inheritdoc}
     *
     * @throws Exception
     * @throws InvalidConfigException
     */
    protected function prepareModels()
    {
        if (!$this->query instanceof Query) {
            throw new InvalidConfigException('The "query" property must be an instance "' . Query::class . '" or its subclasses.');
        }

        $query = clone $this->query;

        if (($pagination = $this->getPagination()) !== false) {
            // pagination fails to validate page number because the total count is unknown at this stage
            $pagination->validatePage = false;
            $query->limit($pagination->getLimit())->offset($pagination->getOffset());
        }

        if (($sort = $this->getSort()) !== false) {
            $query->addOrderBy($sort->getOrders());
        }

        if (is_array($results = $query->search($this->db))) {
            $this->setQueryResults($results);
            if ($pagination !== false) {
                $pagination->totalCount = $this->getTotalCount();
            }
            return $results['hits']['hits'];
        }

        $this->setQueryResults([]);

        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidConfigException
     */
    protected function prepareTotalCount(): int
    {
        if (!$this->query instanceof Query) {
            throw new InvalidConfigException(
                'The "query" property must be an instance "' . Query::class . '" or its subclasses.'
            );
        }

        $results = $this->getQueryResults();

        if (isset($results['hits']['total'])) {
            return is_array($results['hits']['total'])
                ? (int) $results['hits']['total']['value']
                : (int) $results['hits']['total'];
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    protected function prepareKeys($models): array
    {
        $keys = [];

        if ($this->key !== null) {
            foreach ($models as $model) {
                if (is_string($this->key)) {
                    $keys[] = $model[$this->key];
                } else {
                    $keys[] = ($this->key)($model);
                }
            }

            return $keys;
        }

        if ($this->query instanceof ActiveQueryInterface) {
            foreach ($models as $model) {
                $keys[] = $model->primaryKey;
            }
            return $keys;
        }

        return array_keys($models);
    }

    /**
     * {@inheritdoc}
     */
    public function refresh(): void
    {
        parent::refresh();

        $this->_queryResults = null;
    }
}
