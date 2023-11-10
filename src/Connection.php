<?php

declare(strict_types=1);

/**
 * @link https://www.yiiframework.com/
 *
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\elasticsearch;

use CurlHandle;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\InvalidArgumentException;
use yii\helpers\Json;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_reset;
use function curl_setopt;
use function curl_setopt_array;
use function explode;
use function function_exists;
use function get_object_vars;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function mb_strlen;
use function random_int;
use function reset;
use function str_contains;
use function strncmp;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function urlencode;

/**
 * Elasticsearch Connection is used to connect to an Elasticsearch cluster version 0.20 or higher.
 *
 * @property string $driverName Name of the DB driver.
 * @property bool $isActive Whether the DB connection is established.
 * @property QueryBuilder $queryBuilder
 *
 * @author Carsten Brandt <mail@cebe.cc>
 */
class Connection extends Component
{
    /**
     * @event Event an event that is triggered after a DB connection is established.
     */
    public const EVENT_AFTER_OPEN = 'afterOpen';

    /**
     * @var bool whether to autodetect available cluster nodes on [[open()]].
     */
    public bool $autodetectCluster = true;
    /**
     * @var array The Elasticsearch cluster nodes to connect to.
     *
     * This is populated with the result of a cluster nodes request when [[autodetectCluster]] is true.
     *
     * Additional special options:
     *
     *  - `auth`: overrides [[auth]] property. For example:
     *
     * ```php
     * [
     *     'http_address' => 'inet[/127.0.0.1:9200]',
     *     'auth' => [
     *         'username' => 'yiiuser',
     *         'password' => 'yiipw'
     *     ], // Overrides the `auth` property of the class with specific login and password
     *     //'auth' => [
     *         'username' => 'yiiuser',
     *         'password' => 'yiipw'
     *     ], // Disabled auth regardless of `auth` property of the class
     * ]
     * ```
     *
     *  - `protocol`: explicitly sets the protocol for the current node (useful when manually defining an HTTPS cluster)
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/cluster-nodes-info.html#cluster-nodes-info
     */
    public array $nodes = [
        ['http_address' => 'inet[/127.0.0.1:9200]'],
    ];
    /**
     * @var int|string|null the active node. Key of one of the [[nodes]]. Will be randomly selected on [[open()]].
     */
    public string|int|null $activeNode = null;
    /**
     * @var array Authentication data used to connect to the Elasticsearch node.
     *
     * Array elements:
     *
     *  - `username`: the username for authentication.
     *  - `password`: the password for authentication.
     *
     * Array either MUST contain both username and password on not contain any authentication credentials.
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/security-api-authenticate.html
     */
    public array $auth = [];
    /**
     * Elasticsearch has no knowledge of the protocol used to access its nodes.
     * Specifically, cluster autodetect request returns node hosts and ports, but not the protocols to access them.
     * Therefore, we need to specify a default protocol here, which can be overridden for specific nodes in the
     * [[nodes]] property.
     * If [[autodetectCluster]] is true, all nodes received from cluster will be set to use the protocol defined by
     * [[defaultProtocol]].
     *
     * @var string Default protocol to connect to nodes.
     */
    public string $defaultProtocol = 'http';
    /**
     * @var float|null timeout to use for connecting to an Elasticsearch node.
     * This value will be used to configure the curl `CURLOPT_CONNECTTIMEOUT` option.
     * If not set, no explicit timeout will be set for curl.
     */
    public float|null $connectionTimeout = null;
    /**
     * @var float|null timeout to use when reading the response from an Elasticsearch node.
     * This value will be used to configure the curl `CURLOPT_TIMEOUT` option.
     * If not set, no explicit timeout will be set for curl.
     */
    public float|null $dataTimeout = null;
    /**
     * @var array additional options used to configure curl session.
     */
    public array $curlOptions = [];
    /**
     * @var int version of the domain-specific language to use with the server.
     * This must be set to the major version of the Elasticsearch server in use, e.g. `5` for Elasticsearch 5.x.x, `6`
     * for Elasticsearch 6.x.x, and `7` for Elasticsearch 7.x.x.
     */
    public int $dslVersion = 5;

    /**
     * @var CurlHandle|null the curl instance returned by [curl_init()](https://php.net/manual/en/function.curl-init.php).
     */
    private CurlHandle|null $_curl = null;

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        foreach ($this->nodes as &$node) {
            if (!isset($node['http_address'])) {
                throw new InvalidConfigException(
                    'Elasticsearch node needs at least a http_address configured.'
                );
            }

            if (!isset($node['protocol'])) {
                $node['protocol'] = $this->defaultProtocol;
            }

            if (!in_array($node['protocol'], ['http', 'https'])) {
                throw new InvalidConfigException('Valid node protocol settings are "http" and "https".');
            }
        }
    }

    /**
     * Closes the connection when this component is being serialized.
     */
    public function __sleep()
    {
        $this->close();

        return array_keys(get_object_vars($this));
    }

    /**
     * Returns a value indicating whether the DB connection is established.
     *
     * @return bool whether the DB connection is established.
     */
    public function getIsActive(): bool
    {
        return $this->activeNode !== null;
    }

    /**
     * Establishes a DB connection.
     * It does nothing if a DB connection has already been established.
     *
     * @throws Exception if connection fails.
     * @throws InvalidConfigException
     * @throws \Exception
     */
    public function open(): void
    {
        if ($this->activeNode !== null) {
            return;
        }

        if (empty($this->nodes)) {
            throw new InvalidConfigException('Elasticsearch needs at least one node to operate.');
        }

        $this->_curl = curl_init();

        if ($this->autodetectCluster) {
            $this->populateNodes();
        }

        $this->selectActiveNode();

        Yii::debug(
            'Opening connection to Elasticsearch. Nodes in cluster: ' . count($this->nodes) . ', active node: ' .
            $this->nodes[$this->activeNode]['http_address'],
            __CLASS__
        );

        $this->initConnection();
    }

    /**
     * Populates [[nodes]] with the result of a cluster nodes request.
     *
     * @throws Exception if no active node(s) found.
     * @throws InvalidConfigException
     */
    protected function populateNodes(): void
    {
        $node = reset($this->nodes);
        $host = $node['http_address'];
        $protocol = $node['protocol'] ?? $this->defaultProtocol;

        if (strncmp($host, 'inet[/', 6) === 0) {
            $host = substr($host, 6, -1);
        }

        $response = $this->httpRequest('GET', "$protocol://$host/_nodes/_all/http");

        if (!empty($response['nodes'])) {
            $nodes = $response['nodes'];
        } else {
            $nodes = [];
        }

        foreach ($nodes as $key => &$node) {
            // Make sure that nodes have a 'http_address' property, which is not the case if you're using AWS
            // Elasticsearch service (at least as of Oct., 2015). - TO BE VERIFIED
            // Temporary workaround - simply ignore all invalid nodes
            if (!isset($node['http']['publish_address'])) {
                unset($nodes[$key]);
            }

            $node['http_address'] = $node['http']['publish_address'];

            // Protocol is not a standard ES node property, so we add it manually
            $node['protocol'] = $this->defaultProtocol;
        }

        if (!empty($nodes)) {
            $this->nodes = array_values($nodes);
        } else {
            curl_close($this->_curl);

            throw new Exception(
                'Cluster autodetection did not find any active node. Make sure a GET /_nodes reguest on the ' .
                'hosts defined in the config returns the "http_address" field for each node.'
            );
        }
    }

    /**
     * Select active node randomly.
     *
     * @throws \Exception
     */
    protected function selectActiveNode(): void
    {
        $keys = array_keys($this->nodes);
        $this->activeNode = $keys[random_int(0, count($keys) - 1)];
    }

    /**
     * Closes the currently active DB connection.
     * It does nothing if the connection is already closed.
     */
    public function close(): void
    {
        if ($this->activeNode === null) {
            return;
        }

        Yii::debug(
            'Closing connection to Elasticsearch. Active node was: ' .
            $this->nodes[$this->activeNode]['http']['publish_address'],
            __CLASS__,
        );

        $this->activeNode = null;

        if ($this->_curl) {
            curl_close($this->_curl);
            $this->_curl = null;
        }
    }

    /**
     * Initializes the DB connection.
     * This method is invoked right after the DB connection is established.
     * The default implementation triggers an [[EVENT_AFTER_OPEN]] event.
     */
    protected function initConnection(): void
    {
        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    /**
     * Returns the name of the DB driver for the current [[dsn]].
     *
     * @return string name of the DB driver.
     */
    public function getDriverName(): string
    {
        return 'elasticsearch';
    }

    /**
     * Creates a command for execution.
     *
     * @param array $config the configuration for the Command class.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return Command the DB command.
     */
    public function createCommand(array $config = []): Command
    {
        $this->open();
        $config['db'] = $this;

        return new Command($config);
    }

    /**
     * Creates a bulk command for execution.
     *
     * @param array $config the configuration for the [[BulkCommand]] class.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return BulkCommand the DB command.
     */
    public function createBulkCommand(array $config = []): BulkCommand
    {
        $this->open();
        $config['db'] = $this;

        return new BulkCommand($config);
    }

    /**
     * Creates new query builder instance.
     *
     * @return QueryBuilder
     */
    public function getQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * Performs GET HTTP request.
     *
     * @param array|string $url URL.
     * @param array $options URL options.
     * @param string|null $body request body.
     * @param bool $raw if response body contains JSON and should be decoded.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed response.
     */
    public function get(array|string $url, array $options = [], string $body = null, bool $raw = false): mixed
    {
        $this->open();

        return $this->httpRequest('GET', $this->createUrl($url, $options), $body, $raw);
    }

    /**
     * Performs HEAD HTTP request.
     *
     * @param array|string $url URL.
     * @param array $options URL options.
     * @param string|null $body request body.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed response.
     */
    public function head(array|string $url, array $options = [], string $body = null): mixed
    {
        $this->open();

        return $this->httpRequest('HEAD', $this->createUrl($url, $options), $body);
    }

    /**
     * Performs POST HTTP request.
     *
     * @param array|string $url URL.
     * @param array $options URL options.
     * @param string|null $body request body.
     * @param bool $raw if response body contains JSON and should be decoded.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed response
     */
    public function post(array|string $url, array $options = [], string $body = null, bool $raw = false): mixed
    {
        $this->open();

        return $this->httpRequest('POST', $this->createUrl($url, $options), $body, $raw);
    }

    /**
     * Performs PUT HTTP request.
     *
     * @param array|string $url URL.
     * @param array $options URL options.
     * @param string|null $body request body.
     * @param bool $raw if response body contains JSON and should be decoded.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed response
     */
    public function put(array|string $url, array $options = [], string $body = null, bool $raw = false): mixed
    {
        $this->open();

        return $this->httpRequest('PUT', $this->createUrl($url, $options), $body, $raw);
    }

    /**
     * Performs DELETE HTTP request.
     *
     * @param array|string $url URL.
     * @param array $options URL options.
     * @param string|null $body request body.
     * @param bool $raw if response body contains JSON and should be decoded.
     *
     * @throws InvalidConfigException
     * @throws Exception
     *
     * @return mixed response.
     */
    public function delete(array|string $url, array $options = [], string $body = null, bool $raw = false): mixed
    {
        $this->open();

        return $this->httpRequest('DELETE', $this->createUrl($url, $options), $body, $raw);
    }

    /**
     * Creates URL.
     *
     * @param array|string $path path.
     * @param array $options URL options.
     */
    private function createUrl(array|string $path, array $options = []): array
    {
        if (!is_string($path)) {
            $url = implode(
                '/',
                array_map(
                    static function ($a) {
                        return urlencode(is_array($a) ? implode(',', $a) : (string) $a);
                    },
                    $path,
                ),
            );

            if (!empty($options)) {
                $url .= '?' . http_build_query($options);
            }
        } else {
            $url = $path;

            if (!empty($options)) {
                $url .= (!str_contains($url, '?') ? '?' : '&') . http_build_query($options);
            }
        }

        $node = $this->nodes[$this->activeNode];
        $protocol = $node['protocol'] ?? $this->defaultProtocol;
        $host = $node['http_address'];

        return [$protocol, $host, $url];
    }

    /**
     * Performs HTTP request.
     *
     * @param string $method method name.
     * @param array|string $url URL.
     * @param string|null $requestBody request body.
     * @param bool $raw if response body contains JSON and should be decoded.
     *
     * @throws InvalidConfigException
     * @throws Exception if request failed.
     *
     * @return mixed if request failed.
     */
    protected function httpRequest(
        string $method,
        array|string $url,
        string $requestBody = null,
        bool $raw = false
    ): mixed {
        $method = strtoupper($method);

        // response body and headers
        $headers = [];
        $headersFinished = false;
        $body = '';

        $options = [
            CURLOPT_USERAGENT => 'Yii Framework ' . Yii::getVersion() . ' ' . __CLASS__,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            // https://www.php.net/manual/en/function.curl-setopt.php#82418
            CURLOPT_HTTPHEADER => [
                'Expect:',
                'Content-Type: application/json',
            ],

            CURLOPT_WRITEFUNCTION => static function ($curl, $data) use (&$body) {
                $body .= $data;
                return mb_strlen($data, '8bit');
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, $data) use (&$headers, &$headersFinished) {
                if ($data === '') {
                    $headersFinished = true;
                } elseif ($headersFinished) {
                    $headersFinished = false;
                }

                if (!$headersFinished && ($pos = strpos($data, ':')) !== false) {
                    $headers[strtolower(substr($data, 0, $pos))] = trim(substr($data, $pos + 1));
                }

                return mb_strlen($data, '8bit');
            },
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FORBID_REUSE => false,
        ];

        foreach ($this->curlOptions as $key => $value) {
            $options[$key] = $value;
        }

        if (
            !empty($this->auth) ||
            (isset($this->nodes[$this->activeNode]['auth']) && $this->nodes[$this->activeNode]['auth'] !== false)
        ) {
            $auth = $this->nodes[$this->activeNode]['auth'] ?? $this->auth;

            if (empty($auth['username'])) {
                throw new InvalidConfigException('Username is required to use authentication');
            }

            if (empty($auth['password'])) {
                throw new InvalidConfigException('Password is required to use authentication');
            }

            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $options[CURLOPT_USERPWD] = $auth['username'] . ':' . $auth['password'];
        }

        if ($this->connectionTimeout !== null) {
            $options[CURLOPT_CONNECTTIMEOUT] = $this->connectionTimeout;
        }
        if ($this->dataTimeout !== null) {
            $options[CURLOPT_TIMEOUT] = $this->dataTimeout;
        }
        if ($requestBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $requestBody;
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset($options[CURLOPT_WRITEFUNCTION]);
        } else {
            $options[CURLOPT_NOBODY] = false;
        }

        if (is_array($url)) {
            [$protocol, $host, $q] = $url;

            if (strncmp($host, 'inet[', 5) === 0) {
                $host = substr($host, 5, -1);
                if (($pos = strpos($host, '/')) !== false) {
                    $host = substr($host, $pos + 1);
                }
            }

            $profile = "$method $q#$requestBody";
            $url = "$protocol://$host/$q";
        } else {
            $profile = false;
        }

        Yii::debug("Sending request to Elasticsearch node: $method $url\n$requestBody", __METHOD__);

        if ($profile !== false) {
            Yii::beginProfile($profile, __METHOD__);
        }

        $this->resetCurlHandle();
        curl_setopt($this->_curl, CURLOPT_URL, $url);
        curl_setopt_array($this->_curl, $options);

        if (curl_exec($this->_curl) === false) {
            throw new Exception(
                'Elasticsearch request failed: ' . curl_errno($this->_curl) . ' - ' .
                curl_error($this->_curl),
                [
                    'requestMethod' => $method,
                    'requestUrl' => $url,
                    'requestBody' => $requestBody,
                    'responseHeaders' => $headers,
                    'responseBody' => $this->decodeErrorBody($body),
                ],
            );
        }

        $responseCode = curl_getinfo($this->_curl, CURLINFO_HTTP_CODE);

        if ($profile !== false) {
            Yii::endProfile($profile, __METHOD__);
        }

        if ($responseCode >= 200 && $responseCode < 300) {
            if ($method === 'HEAD') {
                return true;
            }
            if (isset($headers['content-length']) && ($len = mb_strlen($body, '8bit')) < $headers['content-length']) {
                throw new Exception(
                    "Incomplete data received from Elasticsearch: $len < {$headers['content-length']}",
                    [
                        'requestMethod' => $method,
                        'requestUrl' => $url,
                        'requestBody' => $requestBody,
                        'responseCode' => $responseCode,
                        'responseHeaders' => $headers,
                        'responseBody' => $body,
                    ],
                );
            }
            if (isset($headers['content-type'])) {
                if (!strncmp($headers['content-type'], 'application/json', 16)) {
                    return $raw ? $body : Json::decode($body);
                }

                if (!strncmp($headers['content-type'], 'text/plain', 10)) {
                    return $raw ? $body : array_filter(explode("\n", $body));
                }
            }

            throw new Exception(
                'Unsupported data received from Elasticsearch: ' . $headers['content-type'],
                [
                    'requestMethod' => $method,
                    'requestUrl' => $url,
                    'requestBody' => $requestBody,
                    'responseCode' => $responseCode,
                    'responseHeaders' => $headers,
                    'responseBody' => $this->decodeErrorBody($body),
                ],
            );
        }

        if ($responseCode === 404) {
            return false;
        }

        throw new Exception(
            "Elasticsearch request failed with code $responseCode. Response body:\n$body",
            [
                'requestMethod' => $method,
                'requestUrl' => $url,
                'requestBody' => $requestBody,
                'responseCode' => $responseCode,
                'responseHeaders' => $headers,
                'responseBody' => $this->decodeErrorBody($body),
            ],
        );
    }

    private function resetCurlHandle(): void
    {
        // these functions do not get reset by curl automatically
        static $unsetValues = [
            CURLOPT_HEADERFUNCTION => null,
            CURLOPT_WRITEFUNCTION => null,
            CURLOPT_READFUNCTION => null,
            CURLOPT_PROGRESSFUNCTION => null,
            CURLOPT_POSTFIELDS => null,
        ];

        curl_setopt_array($this->_curl, $unsetValues);

        if (function_exists('curl_reset')) { // since PHP 5.5.0
            curl_reset($this->_curl);
        }
    }

    /**
     * Try to decode error information if it is valid json, return it if not.
     */
    protected function decodeErrorBody(string $body): mixed
    {
        try {
            $decoded = Json::decode($body);

            if (isset($decoded['error']) && !is_array($decoded['error'])) {
                $decoded['error'] = preg_replace(
                    '/\b\w+?Exception\[/',
                    "<span style=\"color: red;\">\\0</span>\n               ",
                    $decoded['error'],
                );
            }

            return $decoded;
        } catch(InvalidArgumentException $e) {
            return $body;
        }
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function getNodeInfo()
    {
        return $this->get([]);
    }

    /**
     * @throws Exception
     * @throws InvalidConfigException
     */
    public function getClusterState()
    {
        return $this->get(['_cluster', 'state']);
    }
}
