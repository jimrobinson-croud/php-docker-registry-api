<?php
namespace CroudTech\DockerRegistryApi\Api;

use ArrayAccess;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

class Client
{
    /**
     * HTTP Client
     *
     * @var ClientInterface
     */
    protected $http_client;

    /**
     * The docker cloud api username
     *
     * @var string
     */
    protected $username;

    /**
     * Docker cloud api key
     *
     * @var string
     */
    protected $api_key;

    /**
     * The repostory we want to access
     *
     * @var string
     */
    protected $repository;

    /**
     * The auth scopes
     *
     * @var array
     */
    protected $scopes = [];


    /**
     * The auth token to use for requests
     *
     * @var string
     */
    protected $auth_token = '';

    /**
     * The auth token expiry time
     *
     * @var integer
     */
    protected $auth_token_expires = 0;

    /**
     * Undocumented function
     *
     * @param ClientInterface $client
     * @param [type] $username
     * @param [type] $api_key
     * @param [type] $auth_url
     */
    public function __construct(ClientInterface $client, $username, $api_key, ArrayAccess $config)
    {
        $this->http_client = $client;
        $this->username = $username;
        $this->api_key = $api_key;
    }

    /**
     * Make request
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param boolean $fetch_token
     * @return void
     */
    public function request($method, $uri, $options = [], $fetch_token = true)
    {
        $options['http_errors'] = false;
        $options['headers']['Authorization'] = 'Bearer ' . $this->getAuthToken();
        $response = $this->http_client->request($method, $uri, $options);

        if ($response->getStatusCode() == 401 && $fetch_token) {
            $this->fetchNewAuthToken($response);
            return $this->request($method, $uri, $options, false);
        }

        return $response;
    }

    /**
     * Fetch a new auth token from the registry
     *
     * @return void
     */
    public function fetchNewAuthToken(Response $response, $auth_url = false)
    {
        if ($response->hasHeader('Www-Authenticate') && !$auth_url) {
            $auth_url = $this->parseWwwAuthenticateHeader($response->getHeader('Www-Authenticate')[0]);
        }

        if (!$auth_url) {
            throw new \Exception('Attempted to auth with no auth url set');
        }

        $auth_response = $this->http_client->request('GET', $auth_url, [
            'auth' => [
                $this->username,
                $this->api_key,
            ],
        ]);

        $response_json = json_decode($auth_response->getBody()->__toString(), true);
        $auth_token = $response_json['access_token'];

        return $this->auth_token = $auth_token;
    }

    /**
     * Get the url for basic authentication
     *
     * This will throw an exception if the header is malformed
     *
     * @throws \Exception
     */
    public function parseWwwAuthenticateHeader($www_auth_header) :  string
    {
        if (!preg_match('@^(?P<auth_type>[^\s]+) (?P<auth_data>.*)@', $www_auth_header, $matches)) {
            throw new \Exception('Invalid header');
        }

        preg_match_all('@(?P<key>[a-zA-Z]+)\="(?P<val>[^\"]+)"@', $matches['auth_data'], $part_matches);
        $query_string_ar = array_combine($part_matches['key'], $part_matches['val']);
        $realm = $query_string_ar['realm'];
        unset($query_string_ar['realm'], $query_string_ar['error']);
        return \urldecode(sprintf('%s?%s', $realm, \http_build_query($query_string_ar)));
    }

    /**
     * Undocumented function
     *
     * @return string
     */
    public function getAuthToken() : string
    {
        return $this->auth_token;
    }
}
