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
        $registry_client = new RegistryClient(new GuzzleHttpClient, 'dockeruser', 'apikey', 'myrepo/test');
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
        $mock = (\Mockery::mock(RegistryClient::class, [$client, 'dockeruser', 'apikey', 'myrepo/test']))->makePartial();
        $mock->expects()
            ->getAuthToken()
            ->times(2)
            ->andReturn('testauthtoken');

        $response = $mock->request('GET', '/v2/myrepo/image/tags/list');
        $this->assertEquals($response_json, $response->getBody()->__toString());
    }

    public function testGetTags()
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
            new GuzzleHttpResponse(200, [], $response_json = '{"name": "croudtech/core","tags": ["2.21.3","2.22.1","2.23.0","3.23.0"]}'),
        ]);
        $config = new ArrayIterator([]);
        $handler = GuzzleHttpHandlerStack::create($mock_handler);
        $client = new GuzzleHttpClient(['handler' => $handler]);
        $mock = (\Mockery::mock(RegistryClient::class, [$client, 'dockeruser', 'apikey', 'myrepo/test']))->makePartial();

        $this->assertEquals([
            '2.21.3',
            '2.22.1',
            '2.23.0',
        ], $mock->getTags());

        $this->assertEquals([
            '2.21.3',
            '2.22.1',
            '2.23.0',
        ], $mock->getTags());

        $this->assertEquals([
            '2.21.3',
            '2.22.1',
            '2.23.0',
            '3.23.0',
        ], $mock->getTags(true));
    }

    public function testGetManifest()
    {
        $manifest_json = file_get_contents(__DIR__ . '/data/manifest.json');
        $mock_handler = new GuzzleHttpMockHandler([
            new GuzzleHttpResponse(401, ['Www-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:croudtech/core:pull",error="invalid_token"']),
            new GuzzleHttpResponse(200, [], json_encode([
                'token' => 'testauthtoken',
                'access_token' => 'testauthtoken',
                'expires_in' => 300,
                'issued_at' => date('c')
            ])),
            new GuzzleHttpResponse(200, [], $response_json = $manifest_json),
        ]);
        $config = new ArrayIterator([]);
        $handler = GuzzleHttpHandlerStack::create($mock_handler);
        $client = new GuzzleHttpClient(['handler' => $handler]);
        $mock = (\Mockery::mock(RegistryClient::class, [$client, 'dockeruser', 'apikey', 'myrepo/test']))->makePartial();

        $manifest = $mock->getManifest('2.21.3');
        $this->assertInternalType('array', $manifest);
        $this->assertArrayHasKey('history', $manifest);
        $this->assertArrayHasKey('v1Compatibility', $manifest['history'][0]);
        $this->assertInternalType('array', $manifest['history'][0]['v1Compatibility']);
    }

    public function testEncodeManifestV1()
    {
        $manifest_json = file_get_contents(__DIR__ . '/data/manifest.json');
        $client = new RegistryClient($client = new GuzzleHttpClient(), 'dockeruser', 'apikey', 'myrepo/test');
        $decoded = $client->decodeManifestVersion1($manifest_json);
        $encoded = $client->encodeManifestVersion1($decoded);
        $this->assertInternalType('string', $encoded);
    }

    public function testSearchManifestLabels()
    {
        $manifest_json = file_get_contents(__DIR__ . '/data/manifest.json');
        $mock_handler = new GuzzleHttpMockHandler([
            new GuzzleHttpResponse(401, ['Www-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:croudtech/core:pull",error="invalid_token"']),
            new GuzzleHttpResponse(200, [], json_encode([
                'token' => 'testauthtoken',
                'access_token' => 'testauthtoken',
                'expires_in' => 300,
                'issued_at' => date('c')
            ])),
            new GuzzleHttpResponse(200, [], $response_json = '{"name": "croudtech/core","tags": ["1.0.0","1.0.1","1.0.2","1.0.3"]}'),
            new GuzzleHttpResponse(200, [], file_get_contents(__DIR__ . '/data/manifests/1.0.0.json')),
            new GuzzleHttpResponse(200, [], file_get_contents(__DIR__ . '/data/manifests/1.0.1.json')),
            new GuzzleHttpResponse(200, [], file_get_contents(__DIR__ . '/data/manifests/1.0.2.json')),
            new GuzzleHttpResponse(200, [], file_get_contents(__DIR__ . '/data/manifests/1.0.3.json')),
        ]);
        $config = new ArrayIterator([]);
        $handler = GuzzleHttpHandlerStack::create($mock_handler);
        $client = new GuzzleHttpClient(['handler' => $handler]);
        $mock = (\Mockery::mock(RegistryClient::class, [$client, 'dockeruser', 'apikey', 'myrepo/test']))->makePartial();

        $manifests = $mock->searchLabels('com.croudtech.gitref', 'testlabelsearch3');
        $this->assertInstanceOf('Generator', $manifests);
        $found = false;
        foreach ($manifests as $manifest) {
            $this->assertInternalType('array', $manifest);
            $found = true;
        }
        $this->assertTrue($found);
        // $this->assertArrayHasKey('history', $manifest);
        // $this->assertArrayHasKey('v1Compatibility', $manifest['history'][0]);
        // $this->assertInternalType('array', $manifest['history'][0]['v1Compatibility']);
    }
}
