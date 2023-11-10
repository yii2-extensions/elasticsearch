<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\helpers\ArrayHelper;
use yii\web\HttpException;
use yii\web\Response;
use Yii;

use function mb_strpos;
use function mb_substr;
use function microtime;
use function sprintf;

/**
 * Debug Action is used by [[DebugPanel]] to perform Elasticsearch queries using ajax.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class DebugAction extends Action
{
    /**
     * @var string the connection id to use
     */
    public string $db = '';
    /**
     * @var DebugPanel|null
     */
    public DebugPanel|null $panel = null;
    public $controller;

    /**
     * @throws NotSupportedException
     * @throws Exception
     * @throws InvalidConfigException
     * @throws HttpException
     */
    public function run($logId, $tag): array
    {
        $this->controller->loadData($tag);

        $timings = $this->panel->calculateTimings();
        ArrayHelper::multisort($timings, 3, SORT_DESC);

        if (!isset($timings[$logId])) {
            throw new HttpException(404, 'Log message not found.');
        }

        $message = $timings[$logId][1];
        if (($pos = mb_strpos($message, '#')) !== false) {
            $url = mb_substr($message, 0, $pos);
            $body = mb_substr($message, $pos + 1);
        } else {
            $url = $message;
            $body = null;
        }
        $method = mb_substr($url, 0, $pos = mb_strpos($url, ' '));
        $url = mb_substr($url, $pos + 1);

        $options = ['pretty' => 'true'];

        /* @var $db Connection */
        $db = Yii::$app->get($this->db);
        $time = microtime(true);

        $result = match ($method) {
            'GET' => $db->get($url, $options, $body, true),
            'POST' => $db->post($url, $options, $body, true),
            'PUT' => $db->put($url, $options, $body, true),
            'DELETE' => $db->delete($url, $options, $body, true),
            'HEAD' => $db->head($url, $options, $body),
            default => throw new NotSupportedException("Request method '$method' is not supported by Elasticsearch."),
        };

        $time = microtime(true) - $time;

        if ($result === true) {
            $result = '<span class="label label-success">success</span>';
        } elseif ($result === false) {
            $result = '<span class="label label-danger">no success</span>';
        }

        Yii::$app->response->format = Response::FORMAT_JSON;

        return [
            'time' => sprintf('%.1f ms', $time * 1000),
            'result' => $result,
        ];
    }
}
