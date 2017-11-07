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
     * The docker repo
     *
     * eg yournamespace/yourrepo
     *
     * @var string
     */
    protected $docker_repo;

    /**
     * The url of the docker registry api you want to connect to
     *
     * @var [type]
     */
    protected $docker_api_url = 'https://index.docker.io';

    /**
     * Tags for this repo
     *
     * @var array
     */
    protected $tags;

    /**
     * An array of manifests for this repo
     *
     * @var array
     */
    protected $manifests = [];

    /**
     * Undocumented function
     *
     * @param ClientInterface $client
     * @param [type] $username
     * @param [type] $api_key
     * @param [type] $auth_url
     */
    public function __construct(ClientInterface $client, $username, $api_key, $docker_repo, $docker_api_url = 'https://index.docker.io')
    {
        $this->http_client = $client;
        $this->username = $username;
        $this->api_key = $api_key;
        $this->docker_repo = $docker_repo;
        $this->docker_api_url = $docker_api_url;
    }

    /**
     * Make request
     *
     * @param string $method
     * @param string $path
     * @param array $options
     * @param boolean $fetch_token
     * @return void
     */
    public function request($method, $path, $options = [], $fetch_token = true)
    {
        $url = $this->docker_api_url . $path;
        $options['http_errors'] = false;
        $options['headers']['Authorization'] = 'Bearer ' . $this->getAuthToken();
        $response = $this->http_client->request($method, $url, $options);

        if ($response->getStatusCode() == 401 && $fetch_token) {
            $this->fetchNewAuthToken($response);
            return $this->request($method, $url, $options, false);
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

    /**
     * Get a list of tags, cached locally unless $refetch=true is set
     *
     * @param boolean $refetch  Re-fetch from API
     * @return array
     */
    public function getTags($refetch = false) : array
    {
        if (is_null($this->tags) || $refetch === true) {
            $response = $this->request('GET', sprintf('/v2/%s/tags/list', $this->docker_repo));
            if ($response->getStatusCode() == 200 && $response_array = json_decode($response->getBody()->__toString(), true)) {
                return $this->tags = $response_array['tags'];
            }
            throw new \Exception('Invalid response from registry');
        } else {
            return $this->tags;
        }
    }

    /**
     * Get the manifest for a tag
     *
     * @param string $tag
     * @return void
     */
    public function getManifest($tag, $refetch = false) : array
    {
        if (!isset($this->manifests[$tag]) || $refetch === true) {
            $response = $this->request('GET', sprintf('/v2/%s/manifests/%s', $this->docker_repo, $tag));
            return $this->manifests[$tag] = $this->parseManifest($response->getBody()->__toString());
            throw new \Exception('Invalid response from registry');
        } else {
            return $this->tags;
        }
    }

    /**
     * Parse the manifest json
     *
     * @param string $manifest
     * @return array
     */
    public function parseManifest($manifest) : array
    {
        if ($manifest_array = json_decode($manifest, true)) {
            if (!isset($manifest_array['schemaVersion'])) {
                throw new \Exception('Invalid manifest, no schema version found, unable to parse');
            }
            $parser_method = 'decodeManifestVersion' . $manifest_array['schemaVersion'];
            return $this->$parser_method($manifest_array);
        }
        throw new \Exception('Invalid manifest, unable to parse');
    }

    /**
     * Decode manifest json for schema version 1
     *
     * @return array
     */
    public function decodeManifestVersion1($manifest_array) : array
    {
        if (is_string($manifest_array)) {
            $manifest_array = json_decode($manifest_array, true);
        }

        if (isset($manifest_array['history'])) {
            foreach ($manifest_array['history'] as $v1key => $v1json) {
                $manifest_array['history'][$v1key]['v1Compatibility'] = json_decode($v1json['v1Compatibility'], true);
            }
        }

        return $manifest_array;
    }

    /**
     * Ecnode manifest json for schema version 1
     *
     * @return string
     */
    public function encodeManifestVersion1(array $manifest_array) : string
    {
        if (isset($manifest_array['history'])) {
            foreach ($manifest_array['history'] as $v1key => $v1json) {
                $manifest_array['history'][$v1key]['v1Compatibility'] = json_encode($v1json['v1Compatibility']);
            }
        }

        return json_encode($manifest_array);
    }

    public function decodeManifestVersion2() : array
    {
        throw new \Exception('Not Implemented');
    }

    /**
     *
     *
     * @param string $label
     * @param string $value
     * @yields array
     * @return void
     */
    public function searchLabels($label, $value)
    {
        foreach ($this->getTags() as $tag) {
            $manifest = $this->getManifest($tag);
            if (isset($manifest['history'][0]['v1Compatibility']['config']['Labels'][$label]) && $manifest['history'][0]['v1Compatibility']['config']['Labels'][$label] == $value) {
                yield $manifest;
            }
        }
    }

    /**
     * Re-tag a tag
     *
     * @param array $manifest
     * @param The new tag $new_tag
     * @return void
     */
    public function reTag($manifest, $new_tag)
    {
        $encoded_manifest = $this->encodeManifestVersion1($manifest);
        return $this->request('PUT', sprintf('/v2/%s/manifests/%s', $this->docker_repo, $new_tag));
    }
}
