<?php
namespace CroudTech\DockerRegistryApi\Tests;

use \Firebase\JWT\JWT;
use ArrayIterator;
use CroudTech\DockerRegistryApi\Api\Client as RegistryClient;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Handler\MockHandler as GuzzleHttpMockHandler;
use GuzzleHttp\HandlerStack as GuzzleHttpHandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleHttpRequest;
use GuzzleHttp\Psr7\Response as GuzzleHttpResponse;

class ClientTest extends TestCase
{
    /**
     * Make sure that the auth URL is correctly generated
     *
     * @return void
     */
    public function testParseWwwAuthenticateHeader()
    {
        $config = new ArrayIterator([]);
        $registry_client = new RegistryClient(new GuzzleHttpClient, 'dockeruser', 'apikey', $config);
        $header = 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:mydocker/repo:pull,push",error="invalid_token"';
        $this->assertEquals('https://auth.docker.io/token?service=registry.docker.io&scope=repository:mydocker/repo:pull,push', $registry_client->parseWwwAuthenticateHeader($header));
    }

    /**
     * Check that making a request calls the correct auth methods

     * @return void
     */
    public function testRequestCallsAuth()
    {
        $mock_handler = new GuzzleHttpMockHandler([
            new GuzzleHttpResponse(401, ['Www-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:croudtech/core:pull",error="invalid_token"']),
            new GuzzleHttpResponse(200, [], json_encode([
                'token' => 'testauthtoken',
                'access_token' => 'testauthtoken',
                'expires_in' => 300,
                'issued_at' => date('c')
            ])),
            new GuzzleHttpResponse(200, [], $response_json = '{"name": "croudtech/core","tags": ["2.21.3","2.22.1","2.23.0"]}'),
        ]);
        $config = new ArrayIterator([]);
        $handler = GuzzleHttpHandlerStack::create($mock_handler);
        $client = new GuzzleHttpClient(['handler' => $handler]);
        $mock = (\Mockery::mock(RegistryClient::class, [$client, 'dockeruser', 'apikey', $config]))->makePartial();
        $mock->expects()
            ->getAuthToken()
            ->times(2)
            ->andReturn('testauthtoken');

        $response = $mock->request('/v2/myrepo/image/tags/list', 'GET');
        $this->assertEquals($response_json, $response->getBody()->__toString());
    }
}
