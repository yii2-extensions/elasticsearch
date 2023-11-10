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
use Throwable;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

use function array_map;
use function implode;

/**
 * ElasticsearchTarget stores log messages in an Elasticsearch index.
 *
 * @author Eugene Terentev <eugene@terentev.net>
 */
class ElasticsearchTarget extends Target
{
    /**
     * @var string Elasticsearch index name.
     */
    public string $index = 'yii';
    /**
     * @var string Elasticsearch type name.
     */
    public string $type = 'log';
    /**
     * @var array|Connection|string the Elasticsearch connection object or the application component ID
     * of the Elasticsearch connection.
     */
    public string|array|Connection $db = 'elasticsearch';
    /**
     * @var array $options URL options.
     */
    public array $options = [];
    /**
     * @var bool If true, context will be logged as a separate message after all other messages.
     */
    public bool $logContext = true;
    /**
     * @var bool If true, context will be included in every message.
     * This is convenient if you log application errors and analyze them with tools like Kibana.
     */
    public bool $includeContext = false;
    /**
     * @var bool If true, a context message will cache once it's been created. Makes sense to use with
     * [[includeContext]].
     */
    public bool $cacheContext = false;

    /**
     * @var array|string|null Context message cache (can be used multiple times if context is appended to every message).
     */
    protected array|string|null $_contextMessage = null;

    /**
     * This method will initialize the [[elasticsearch]] property to make sure it refers to a valid Elasticsearch
     * connection.
     *
     * @throws InvalidConfigException if [[elasticsearch]] is invalid.
     */
    public function init(): void
    {
        parent::init();

        $this->db = Instance::ensure($this->db, Connection::class);
    }

    /**
     * @inheritdoc
     *
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function export(): void
    {
        $messages = array_map([$this, 'prepareMessage'], $this->messages);
        $body = implode("\n", $messages) . "\n";

        if ($this->db->dslVersion >= 7) {
            $this->db->post([$this->index, '_bulk'], $this->options, $body);
        } else {
            $this->db->post([$this->index, $this->type, '_bulk'], $this->options, $body);
        }
    }

    /**
     * If [[includeContext]] property is false, returns context message normally.
     * If [[includeContext]] is true, returns an empty string (so that context message in [[collect]] is not generated),
     * expecting that context will be appended to every message in [[prepareMessage]].
     *
     * @return array|string|null the context information
     */
    protected function getContextMessage(): array|string|null
    {
        if (null === $this->_contextMessage || !$this->cacheContext) {
            $this->_contextMessage = ArrayHelper::filter($GLOBALS, $this->logVars);
        }

        return $this->_contextMessage;
    }

    /**
     * Processes the given log messages.
     * This method will filter the given messages with [[levels]] and [[categories]].
     * And if requested, it will also export the filtering result to a specific medium (e.g. email).
     * Depending on the [[includeContext]] attribute, a context message will be either created or ignored.
     *
     * @param array $messages log messages to be processed. See [[Logger::messages]] for the structure of each message.
     * @param bool $final whether this method is called at the end of the current application.
     *
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function collect($messages, $final): void
    {
        $this->messages = array_merge($this->messages, static::filterMessages($messages, $this->getLevels(), $this->categories, $this->except));
        $count = count($this->messages);
        if ($count > 0 && ($final || ($this->exportInterval > 0 && $count >= $this->exportInterval))) {
            if (!$this->includeContext && $this->logContext) {
                $context = $this->getContextMessage();

                if (!empty($context)) {
                    $this->messages[] = [$context, Logger::LEVEL_INFO, 'application', YII_BEGIN_TIME];
                }
            }

            // set exportInterval to zero to avoid triggering export again while exporting
            $oldExportInterval = $this->exportInterval;
            $this->exportInterval = 0;
            $this->export();
            $this->exportInterval = $oldExportInterval;
            $this->messages = [];
        }
    }

    /**
     * Prepares a log message.
     *
     * @param array $message The log message to be formatted.
     *
     * @return string
     */
    public function prepareMessage(array $message): string
    {
        [$text, $level, $category, $timestamp] = $message;

        $result = [
            'category' => $category,
            'level' => Logger::getLevelName($level),
            '@timestamp' => date('c', (int) $timestamp),
        ];

        if (isset($message[4])) {
            $result['trace'] = $message[4];
        }

        if (!is_string($text)) {
            // exceptions may not be serializable if in the call stack somewhere is a Closure
            if ($text instanceof Throwable) {
                $text = (string) $text;
            } else {
                $text = VarDumper::export($text);
            }
        }
        $result['message'] = $text;

        if ($this->includeContext) {
            $result['context'] = $this->getContextMessage();
        }

        return implode("\n", [
            Json::encode([
                'index' => new stdClass(),
            ]),
            Json::encode($result),
        ]);
    }
}
