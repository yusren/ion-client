<?php

namespace Ptpn\IonClient\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Ptpn\IonClient\Exceptions\IonClientException;
use Ptpn\IonClient\Facades\IonClient as IonClientFacade;
use Ptpn\IonClient\IonClient;
use Ptpn\IonClient\IonClientServiceProvider;

class IonClientTest extends TestCase
{
    /**
     * Define the service providers used by the package.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [IonClientServiceProvider::class];
    }

    /**
     * Define the aliases used by the package.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageAliases($app)
    {
        return [
            'IonClient' => IonClientFacade::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an IonClient whose HTTP layer is replaced by a mock handler.
     * M4: Uses constructor injection instead of ReflectionClass.
     *
     * @param array $responses  Guzzle Response objects to queue.
     * @param array $config     Optional config overrides.
     * @return IonClient
     */
    private function makeClientWithMock(array $responses, array $config = []): IonClient
    {
        $mock    = new MockHandler($responses);
        $stack   = HandlerStack::create($mock);
        $http    = new Client(['handler' => $stack]);

        return new IonClient(array_merge([
            'frontend_url' => 'http://localhost',
        ], $config), $http);
    }

    // -------------------------------------------------------------------------
    // Existing tests
    // -------------------------------------------------------------------------

    public function test_it_loads_default_configuration()
    {
        $config = config('ion-client');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('base_url', $config);
        $this->assertArrayHasKey('client_id', $config);
        $this->assertArrayHasKey('client_secret', $config);
        $this->assertSame('https://ion.palmco.id/api/v2', $config['base_url']);
    }

    public function test_it_resolves_client_from_container()
    {
        $client = $this->app->make(IonClient::class);

        $this->assertInstanceOf(IonClient::class, $client);
    }

    public function test_it_resolves_facade_root()
    {
        $this->assertInstanceOf(IonClient::class, IonClientFacade::getFacadeRoot());
    }

    public function test_callback_redirects_to_frontend_when_code_is_missing()
    {
        $client  = $this->app->make(IonClient::class);
        $request = Request::create('/auth/callback');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('http://localhost', $response->headers->get('Location'));
    }

    public function test_callback_creates_local_session_and_redirects_to_frontend()
    {
        // M4: No more ReflectionClass — inject mock via constructor.
        $client = $this->makeClientWithMock([
            new Response(200, [], json_encode([
                'session_id' => 'sso-session-id-1234567890abc',
            ])),
            new Response(200, [], json_encode([
                'data' => [
                    'name'  => 'John Doe',
                    'email' => 'john@example.com',
                ],
            ])),
        ]);

        $request = Request::create('/auth/callback?code=abc123');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('http://localhost', $response->headers->get('Location'));

        $session = $request->session();
        $this->assertEquals('active', $session->get('status'));
        $this->assertEquals('sso-session-id-1234567890abc', $session->get('sso_session_id'));
        $this->assertEquals(
            json_encode(['name' => 'John Doe', 'email' => 'john@example.com']),
            $session->get('user_data')
        );

        $cookies       = $response->headers->getCookies();
        $sessionCookie = collect($cookies)->first(fn ($c) => $c->getName() === 'ion_session');
        $this->assertNotNull($sessionCookie);
        $this->assertEquals('sso-session-id-1234567890abc', $sessionCookie->getValue());
    }

    // -------------------------------------------------------------------------
    // New security / regression tests
    // -------------------------------------------------------------------------

    /**
     * C1 — Open Redirect: a malicious return_url from a different host must
     * be silently ignored; redirect must still go to the configured frontend_url.
     */
    public function test_callback_ignores_malicious_return_url()
    {
        $client = $this->makeClientWithMock([
            new Response(200, [], json_encode([
                'session_id' => 'sso-session-id-1234567890abc',
            ])),
            new Response(200, [], json_encode([
                'data' => ['name' => 'John Doe'],
            ])),
        ], ['frontend_url' => 'http://localhost']);

        $request = Request::create('/auth/callback?code=abc123', 'GET', [], [
            'return_url' => 'https://evil.com/steal',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        // Must NOT redirect to evil.com
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('http://localhost', $response->headers->get('Location'));
    }

    /**
     * C1 — Safe return_url on the same host must still be honoured.
     */
    public function test_callback_allows_safe_return_url_same_host()
    {
        $client = $this->makeClientWithMock([
            new Response(200, [], json_encode([
                'session_id' => 'sso-session-id-1234567890abc',
            ])),
            new Response(200, [], json_encode([
                'data' => ['name' => 'John Doe'],
            ])),
        ], ['frontend_url' => 'http://localhost']);

        $request = Request::create('/auth/callback?code=abc123', 'GET', [], [
            'return_url' => 'http://localhost/dashboard',
        ]);
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('http://localhost/dashboard', $response->headers->get('Location'));
    }

    /**
     * C3 — Session Fixation: a session ID that doesn't match the required
     * pattern (min 20 chars, alphanumeric/-/_) must be rejected.
     */
    public function test_callback_rejects_invalid_session_id_format()
    {
        // Short / weird session ID from a potentially tampered SSO response
        $client = $this->makeClientWithMock([
            new Response(200, [], json_encode([
                'session_id' => 'short',   // too short — < 20 chars
            ])),
        ]);

        $request = Request::create('/auth/callback?code=abc123');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        // Must redirect to frontend without creating a session
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertNull($request->session()->get('status'));
    }

    /**
     * H1 — Silent JSON decode: when ION returns non-JSON (e.g. HTML from a
     * load balancer), the client must throw IonClientException, not return [].
     */
    public function test_request_throws_on_invalid_json_response()
    {
        $client = $this->makeClientWithMock([
            new Response(200, [], '<html>502 Bad Gateway</html>'),
        ]);

        $this->expectException(IonClientException::class);

        $client->checkSession('any-session-id');
    }

    /**
     * H2 — When getSessionFullInfo() fails, callback() must NOT create a
     * half-baked session and must redirect to the frontend gracefully.
     */
    public function test_callback_redirects_gracefully_when_full_info_fails()
    {
        $client = $this->makeClientWithMock([
            // verify() succeeds
            new Response(200, [], json_encode([
                'session_id' => 'sso-session-id-1234567890abc',
            ])),
            // getSessionFullInfo() returns a server error
            new Response(500, [], json_encode(['message' => 'Internal Server Error'])),
        ]);

        $request = Request::create('/auth/callback?code=abc123');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        $this->assertEquals(302, $response->getStatusCode());
        // Session must NOT be set
        $this->assertNull($request->session()->get('status'));
    }

    /**
     * ION_ENABLED: isEnabled() must reflect the configured value.
     */
    public function test_is_enabled_reflects_configuration()
    {
        $enabledClient = new IonClient(['enabled' => true]);
        $this->assertTrue($enabledClient->isEnabled());

        $disabledClient = new IonClient(['enabled' => false]);
        $this->assertFalse($disabledClient->isEnabled());
    }

    /**
     * ION_ENABLED: when disabled, callback() must skip SSO processing and
     * redirect to the frontend, leaving Laravel Auth/default auth free to handle
     * authentication.
     */
    public function test_callback_redirects_to_frontend_when_disabled()
    {
        $client = new IonClient([
            'enabled'      => false,
            'frontend_url' => 'http://localhost',
        ]);

        $request = Request::create('/auth/callback?code=abc123');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $client->callback($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('http://localhost', $response->headers->get('Location'));
        // No SSO session should be created
        $this->assertNull($request->session()->get('status'));
    }

    /**
     * IonClientException: verify factory method types are set correctly.
     */
    public function test_exception_factory_methods_set_correct_type()
    {
        $this->assertSame(
            IonClientException::TYPE_NETWORK,
            IonClientException::networkError('err')->getType()
        );
        $this->assertSame(
            IonClientException::TYPE_AUTH,
            IonClientException::authFailed('err')->getType()
        );
        $this->assertSame(
            IonClientException::TYPE_RESPONSE,
            IonClientException::invalidResponse('err')->getType()
        );
        $this->assertSame(
            IonClientException::TYPE_CONFIG,
            IonClientException::configError('err')->getType()
        );
    }
}
