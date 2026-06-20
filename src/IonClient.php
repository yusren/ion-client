<?php

namespace Ptpn\IonClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ptpn\IonClient\Exceptions\IonClientException;

class IonClient
{
    /**
     * Package configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * Guzzle HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $http;

    /**
     * Create a new ION client instance.
     *
     * An optional pre-built Guzzle Client can be injected (useful for testing
     * with a MockHandler without needing Reflection).
     *
     * @param array              $config
     * @param \GuzzleHttp\Client|null $http
     */
    public function __construct(array $config = [], ?Client $http = null)
    {
        $this->config = array_merge([
            'enabled'       => true,
            'base_url'      => 'https://ion.palmco.id',
            'client_key'    => '',
            'client_identifier' => '',
            'timeout'       => 30,
            'verify_ssl'    => true,
        ], $config);

        // Backward compatibility: map legacy client_id/client_secret to the
        // new client_key/client_identifier names if the new keys are empty.
        if (empty($this->config['client_key']) && !empty($config['client_id'])) {
            $this->config['client_key'] = $config['client_id'];
        }
        if (empty($this->config['client_identifier']) && !empty($config['client_secret'])) {
            $this->config['client_identifier'] = $config['client_secret'];
        }

        $this->http = $http ?? $this->buildHttpClient();
    }

    /**
     * Build the default Guzzle HTTP client with retry middleware.
     *
     * Retry policy (L4): up to 2 retries on connection error or 5xx response,
     * with a fixed 500 ms delay between attempts.
     *
     * @return \GuzzleHttp\Client
     */
    protected function buildHttpClient(): Client
    {
        $stack = HandlerStack::create();

        // Retry middleware: 2 retries, 500 ms delay, on network error or 5xx
        $stack->push(Middleware::retry(
            function (int $retries, $request, $response = null, $exception = null) {
                if ($retries >= 2) {
                    return false;
                }
                // Retry on connection exception
                if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                    return true;
                }
                // Retry on 5xx server errors
                if ($response && $response->getStatusCode() >= 500) {
                    return true;
                }
                return false;
            },
            function (int $retries): int {
                // Fixed 500 ms delay (in milliseconds)
                return 500;
            }
        ));

        return new Client([
            'handler'     => $stack,
            'base_uri'    => rtrim($this->config['base_url'], '/') . '/api/v2/',
            'timeout'     => (int) $this->config['timeout'],
            'verify'      => (bool) $this->config['verify_ssl'],
            'http_errors' => true,
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Get the configured client key.
     *
     * @return string
     */
    public function getClientKey(): string
    {
        return (string) ($this->config['client_key'] ?? '');
    }

    /**
     * Get the configured client identifier (secret).
     * This value must never be exposed in URLs, HTML, rendered pages, or logs.
     *
     * @return string
     */
    public function getClientIdentifier(): string
    {
        return (string) ($this->config['client_identifier'] ?? '');
    }

    /**
     * Get the default request headers required by ION v2.
     *
     * @return array
     */
    protected function headers(): array
    {
        return [
            'X-Client-ID'     => $this->getClientKey(),
            'X-Client-Secret' => $this->getClientIdentifier(),
            'X-Timestamp'     => (string) time(),
        ];
    }

    /**
     * Send an HTTP request to ION v2.
     *
     * @param string $method
     * @param string $uri
     * @param array  $options
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        $withAuthHeaders = $options['with_auth_headers'] ?? true;
        unset($options['with_auth_headers']);

        if ($withAuthHeaders) {
            $options['headers'] = array_merge(
                $this->headers(),
                $options['headers'] ?? []
            );
        } else {
            $options['headers'] = array_merge(
                [
                    'Accept' => 'application/json',
                ],
                $options['headers'] ?? []
            );
        }

        try {
            $response = $this->http->request($method, ltrim($uri, '/'), $options);
            $body     = (string) $response->getBody();

            // H1: Detect silent JSON decode failure
            if ($body === '') {
                return [];
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw IonClientException::invalidResponse(
                    'ION returned a non-JSON response: ' . json_last_error_msg()
                );
            }

            return $decoded ?? [];
        } catch (IonClientException $e) {
            // Re-throw our own exceptions untouched
            throw $e;
        } catch (RequestException $e) {
            // C2: Use our safe extractor; do NOT pass $e as previous to avoid
            // leaking request headers (including X-Client-Secret) in logs.
            $message = $this->extractErrorMessage($e);

            // Treat 4xx responses as authentication failures — these are not
            // transient network errors but indicate invalid credentials, expired
            // codes, or other client-side authentication issues.
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() >= 400
                && $e->getResponse()->getStatusCode() < 500) {
                throw IonClientException::authFailed($message);
            }

            throw IonClientException::networkError($message);
        } catch (\Throwable $e) {
            throw IonClientException::networkError($e->getMessage());
        }
    }

    /**
     * Extract a readable error message from a Guzzle request exception.
     *
     * @param \GuzzleHttp\Exception\RequestException $e
     * @return string
     */
    protected function extractErrorMessage(RequestException $e): string
    {
        if ($e->hasResponse()) {
            $data = json_decode((string) $e->getResponse()->getBody(), true);

            if (is_array($data)) {
                if (!empty($data['message'])) {
                    return $data['message'];
                }

                if (!empty($data['error'])) {
                    return $data['error'];
                }
            }
        }

        // Return a generic message; never expose $e->getMessage() which
        // may contain the full request URL + headers.
        return 'ION SSO request failed with HTTP ' . ($e->getResponse()
            ? $e->getResponse()->getStatusCode()
            : 'connection error');
    }

    /**
     * Determine whether the ION SSO client is enabled.
     *
     * When disabled, the callback() method will skip SSO processing and
     * redirect to the frontend, allowing the host application to fall back
     * to Laravel's default auth or any other authentication mechanism.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Build the SSO login redirect URL (Step 1).
     *
     * This URL is sent to the user's browser. For security, it includes ONLY
     * client_key and redirect_uri. client_identifier must NEVER appear here.
     *
     * @param string|null $redirectUri
     * @param array       $extra
     * @return string
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function getLoginUrl(?string $redirectUri = null, array $extra = []): string
    {
        $clientKey = $this->getClientKey();

        if (empty($clientKey)) {
            throw IonClientException::configError(
                'ION Client: "client_key" is not configured. '
                . 'Set ION_CLIENT_KEY in your .env file.'
            );
        }

        $baseUrl = rtrim($this->config['base_url'], '/');

        // Enforce HTTPS for security: never build a login URL over plain HTTP.
        if (strpos(strtolower($baseUrl), 'https://') !== 0) {
            throw IonClientException::configError(
                'ION Client: SSO base_url must use HTTPS. Current: ' . $baseUrl
            );
        }

        $redirectUri ??= $this->getFrontendUrl() . '/auth/callback';

        $query = array_merge([
            'client_key'    => $clientKey,
            'redirect_uri'  => $redirectUri,
        ], $extra);

        $queryString = http_build_query($query);

        // ION SSO cannot read percent-encoded slash/colon in redirect_uri;
        // keep those characters literal so the server can parse the URL.
        $queryString = str_replace(
            ['%2F', '%3A'],
            ['/', ':'],
            $queryString
        );

        return $baseUrl . '/auth/login?' . $queryString;
    }

    /**
     * Check whether an SSO session is still active.
     *
     * @param string $sessionId
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function checkSession(string $sessionId): array
    {
        return $this->request('GET', 'auth/check-session', [
            'query' => ['session_id' => $sessionId],
        ]);
    }

    /**
     * Exchange an authorization code for session ID and user data.
     *
     * Step 3 back-channel handshake. The client_identifier is sent in the JSON
     * body, never in the URL or headers exposed to the browser.
     *
     * @param string $code
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function verify(string $code): array
    {
        return $this->request('POST', 'auth/verify', [
            // Step 3 sends credentials in the JSON body, not in headers.
            'with_auth_headers' => false,
            'json' => [
                'code'              => $code,
                'client_key'        => $this->getClientKey(),
                'client_identifier' => $this->getClientIdentifier(),
            ],
        ]);
    }

    /**
     * Retrieve full information of a user session.
     *
     * @param string $sessionId
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function getSessionFullInfo(string $sessionId): array
    {
        return $this->request('POST', 'client/session/full-info', [
            'json' => ['session_id' => $sessionId],
        ]);
    }

    /**
     * Retrieve roles of a user for a specific application.
     *
     * @param string      $sessionId
     * @param string|null $application
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function getUserRoles(string $sessionId, ?string $application = null): array
    {
        $payload = ['session_id' => $sessionId];

        if ($application !== null) {
            $payload['application'] = $application;
        }

        return $this->request('POST', 'client/user/roles', [
            'json' => $payload,
        ]);
    }

    /**
     * Keep a session alive.
     *
     * @param string $sessionId
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function heartbeat(string $sessionId): array
    {
        return $this->request('POST', 'client/heartbeat', [
            'json' => ['session_id' => $sessionId],
        ]);
    }

    /**
     * Log out a user session.
     *
     * @param string $sessionId
     * @return array
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function logout(string $sessionId): array
    {
        return $this->request('POST', 'client/logout', [
            'json' => ['session_id' => $sessionId],
        ]);
    }

    /**
     * Handle SSO callback after user successfully authenticated on ION.
     *
     * Flow (safe order to prevent race condition / half-baked session):
     * 1. Read authorization code from query string.
     * 2. Exchange code for SSO session ID via verify().
     * 3. Validate session ID format (prevent session fixation).
     * 4. Fetch full user data via getSessionFullInfo() — BEFORE touching session.
     * 5. Only if step 4 succeeds: create local Laravel session with SSO ID.
     * 6. Set cookie and redirect to safe frontend URL.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    public function callback(Request $request): RedirectResponse
    {
        // If ION SSO is disabled, skip all SSO processing and let the host
        // application handle authentication via Laravel Auth or another provider.
        if (!$this->isEnabled()) {
            Log::debug('ION SSO callback skipped: integration is disabled');
            return $this->redirectToFrontend($request);
        }

        $code = $request->query('code');

        Log::debug('ION SSO callback received', [
            'code_present' => !empty($code) && is_string($code),
            'code_length'  => is_string($code) ? strlen($code) : 0,
            'cookie_names' => $request->cookies->keys(),
        ]);

        if (empty($code) || !is_string($code)) {
            Log::debug('ION SSO callback aborted: missing or invalid authorization code');
            return $this->redirectToFrontend($request);
        }

        // Step 2: Exchange code → SSO session ID
        try {
            $verifyResponse = $this->verify($code);
            Log::debug('ION SSO verify succeeded', [
                'response_keys' => array_keys($verifyResponse),
            ]);
        } catch (IonClientException $e) {
            Log::debug('ION SSO verify failed', [
                'error' => $e->getMessage(),
                'error_type' => $e->getType(),
            ]);
            return $this->redirectToFrontend($request);
        }

        $ssoSessionId = $this->extractSessionId($verifyResponse);

        Log::debug('ION SSO session ID extracted', [
            'session_id_present' => !empty($ssoSessionId),
            'session_id_length'  => $ssoSessionId ? strlen($ssoSessionId) : 0,
        ]);

        if (empty($ssoSessionId)) {
            return $this->redirectToFrontend($request);
        }

        // C3: Validate session ID format before using it as a local session ID.
        // This prevents session fixation via a crafted/low-entropy session ID.
        if (!preg_match('/^[a-zA-Z0-9\-_]{20,256}$/', $ssoSessionId)) {
            Log::debug('ION SSO callback aborted: invalid session ID format', [
                'session_id_length' => strlen($ssoSessionId),
            ]);
            return $this->redirectToFrontend($request);
        }

        // H2+H3: Fetch full user data BEFORE creating the local session so that
        // if this call fails we don't leave a half-baked session behind.
        try {
            $fullInfoResponse = $this->getSessionFullInfo($ssoSessionId);
            Log::debug('ION SSO full-info succeeded', [
                'response_keys' => array_keys($fullInfoResponse),
            ]);
        } catch (IonClientException $e) {
            Log::debug('ION SSO full-info failed', [
                'error' => $e->getMessage(),
                'error_type' => $e->getType(),
            ]);
            return $this->redirectToFrontend($request);
        }

        $userData = $fullInfoResponse['data'] ?? $fullInfoResponse;

        // All data is available — now it is safe to create the local session.
        $request->session()->invalidate();
        $request->session()->setId($ssoSessionId);
        $request->session()->start();

        $request->session()->put('status', 'active');
        $request->session()->put('sso_session_id', $ssoSessionId);
        $request->session()->put('user_data', json_encode($userData));
        $request->session()->save();

        Log::debug('ION SSO local session saved', [
            'session_keys' => array_keys($request->session()->all()),
        ]);

        // C1: Validate return_url against the configured frontend host before redirect.
        $cookie      = $this->makeSessionCookie($ssoSessionId);
        $returnUrl   = $request->cookie('return_url');
        $destination = ($returnUrl && $this->isSafeRedirectUrl($returnUrl))
            ? $returnUrl
            : $this->getFrontendUrl();

        Log::debug('ION SSO session cookie created', [
            'cookie_name'      => $cookie->getName(),
            'cookie_path'      => $cookie->getPath(),
            'cookie_domain'    => $cookie->getDomain(),
            'cookie_secure'    => $cookie->isSecure(),
            'cookie_http_only' => $cookie->isHttpOnly(),
            'cookie_same_site' => $cookie->getSameSite(),
        ]);

        Log::debug('ION SSO redirecting', ['destination' => $destination]);

        $clearReturnUrlCookie = cookie('return_url', '', -1);

        return redirect($destination)
            ->withCookie($cookie)
            ->withCookie($clearReturnUrlCookie);
    }

    /**
     * Validate that a redirect URL is safe — i.e. its host and scheme match
     * the configured frontend_url. Prevents Open Redirect attacks via a crafted
     * return_url cookie value.
     *
     * @param string $url
     * @return bool
     */
    protected function isSafeRedirectUrl(string $url): bool
    {
        $allowed = $this->config['frontend_url'] ?? '';

        if (empty($allowed) || empty($url)) {
            return false;
        }

        $target  = parse_url($url);
        $base    = parse_url(rtrim($allowed, '/'));

        if (!isset($target['host']) || !isset($base['host'])) {
            return false;
        }

        // Host must match exactly (no subdomain takeover)
        if (strtolower($target['host']) !== strtolower($base['host'])) {
            return false;
        }

        // Scheme must match (http vs https)
        $targetScheme = strtolower($target['scheme'] ?? 'https');
        $baseScheme   = strtolower($base['scheme'] ?? 'https');

        return $targetScheme === $baseScheme;
    }

    /**
     * Get the configured frontend URL.
     *
     * @return string
     * @throws \Ptpn\IonClient\Exceptions\IonClientException
     */
    protected function getFrontendUrl(): string
    {
        $url = $this->config['frontend_url'] ?? '';

        // L3: Don't silently fall back to http://localhost in production.
        if (empty($url)) {
            throw IonClientException::configError(
                'ION Client: "frontend_url" is not configured. '
                . 'Set ION_FRONTEND_URL in your .env file.'
            );
        }

        return $url;
    }

    /**
     * Extract SSO session ID from the verify() response.
     *
     * L2: Only looks for the unambiguous "session_id" key (not generic "id")
     * at the root level or inside a "data"/"user" wrapper.
     *
     * @param array $response
     * @return string|null
     */
    protected function extractSessionId(array $response): ?string
    {
        // Root-level session_id
        if (!empty($response['session_id']) && is_string($response['session_id'])) {
            return $response['session_id'];
        }

        // Nested under "data"
        if (!empty($response['data']['session_id']) && is_string($response['data']['session_id'])) {
            return $response['data']['session_id'];
        }

        // Nested under "user"
        if (!empty($response['user']['session_id']) && is_string($response['user']['session_id'])) {
            return $response['user']['session_id'];
        }

        return null;
    }

    /**
     * Create the session cookie based on package configuration.
     *
     * @param string $ssoSessionId
     * @return \Symfony\Component\HttpFoundation\Cookie
     */
    protected function makeSessionCookie(string $ssoSessionId): \Symfony\Component\HttpFoundation\Cookie
    {
        $cookie = $this->config['cookie'] ?? [];

        // M2: Validate same_site against the browser-recognised set of values.
        $validSameSite = ['Strict', 'Lax', 'None'];
        $sameSite      = in_array($cookie['same_site'] ?? '', $validSameSite, true)
            ? $cookie['same_site']
            : 'Lax';

        return cookie(
            $cookie['name']      ?? 'ion_session',
            $ssoSessionId,
            (int) ($cookie['lifetime'] ?? 1440),
            '/',
            $cookie['domain']    ?? null,
            (bool) ($cookie['secure']    ?? false),
            (bool) ($cookie['http_only'] ?? true),
            false,
            $sameSite
        );
    }

    /**
     * Redirect to the configured frontend URL.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectToFrontend(Request $request): RedirectResponse
    {
        // C1: Also validate return_url on the error/fallback path.
        $returnUrl   = $request->cookie('return_url');
        $destination = ($returnUrl && $this->isSafeRedirectUrl($returnUrl))
            ? $returnUrl
            : $this->getFrontendUrl();

        $clearReturnUrlCookie = cookie('return_url', '', -1);

        return redirect($destination)->withCookie($clearReturnUrlCookie);
    }
}
