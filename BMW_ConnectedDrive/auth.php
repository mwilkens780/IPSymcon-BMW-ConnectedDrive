<?php

declare(strict_types=1);

// ─── BMW API Constants ────────────────────────────────────────────────────────

define('BMW_SERVER_ROW', 'https://cocoapi.bmwgroup.com');
define('BMW_SERVER_NA',  'https://cocoapi.bmwgroup.us');

// OCP subscription keys (plain text)
define('OCP_KEY_ROW', '4f1c85a3-758f-a37d-bbb6-f87044494acfa');
define('OCP_KEY_NA',  '31e102f5-6f7e-7ef3-9044-ddce63891362');

define('BMW_APP_VERSION',   '4.9.2(36892)');
define('BMW_USER_AGENT',    'Dart/3.3 (dart:io)');
define('BMW_X_USER_AGENT',  'android(v1-1403);bmw;4.9.2(36892);row');
define('OAUTH_CONFIG_PATH', '/eadrax-ucs/v1/presentation/oauth/config');

// ─── BMWAuth ──────────────────────────────────────────────────────────────────

class BMWAuth
{
    private string $username;
    private string $password;
    private string $server;
    private string $ocpKey;
    private string $sessionId;

    public function __construct(string $username, string $password, string $region = 'ROW')
    {
        $this->username  = $username;
        $this->password  = $password;
        $this->sessionId = self::uuid4();

        if (strtoupper($region) === 'NA') {
            $this->server = BMW_SERVER_NA;
            $this->ocpKey = OCP_KEY_NA;
        } else {
            $this->server = BMW_SERVER_ROW;
            $this->ocpKey = OCP_KEY_ROW;
        }
    }

    // ─── Public login API ─────────────────────────────────────────────────────

    /**
     * Full login with username/password + hCaptcha.
     * Use only for the first login; the returned store can be persisted and
     * passed to loginWithStore() for all subsequent calls.
     *
     * @throws \RuntimeException on HTTP or protocol errors
     */
    public function login(string $hcaptchaToken = ''): array
    {

        $config  = $this->fetchOAuthConfig();
        $verifier  = self::generateCodeVerifier();
        $challenge = self::createS256CodeChallenge($verifier);
        $state     = self::generateToken();
        $nonce     = self::generateToken();

        $authenticateUrl = str_replace('/token', '/authenticate', $config['tokenEndpoint']);

        $oauthBase = [
            'client_id'             => $config['clientId'],
            'response_type'         => 'code',
            'scope'                 => implode(' ', (array) $config['scopes']),
            'redirect_uri'          => $config['returnUrl'],
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'grant_type'            => 'authorization_code',
        ];

        // Step 2 – Authenticate with username/password; add hCaptcha header only if provided
        $extraHeaders = trim($hcaptchaToken) !== '' ? ['hcaptchatoken' => $hcaptchaToken] : [];
        $authData = $this->postAuthenticate(
            $authenticateUrl,
            array_merge($oauthBase, ['username' => $this->username, 'password' => $this->password]),
            $extraHeaders
        );

        if (empty($authData['redirect_to'])) {
            throw new \RuntimeException('Authenticate (Schritt 2): Kein redirect_to in Antwort.');
        }

        parse_str((string) parse_url($authData['redirect_to'], PHP_URL_QUERY), $redirectQuery);
        $authorization = $redirectQuery['authorization'] ?? '';

        if ($authorization === '') {
            throw new \RuntimeException(
                'Authenticate (Schritt 2): Kein authorization-Parameter in redirect_to: '
                . $authData['redirect_to']
            );
        }

        // Step 3 – Second authenticate call; extracts auth code from 302 Location
        $code = $this->authenticateForCode(
            $authenticateUrl,
            array_merge($oauthBase, ['authorization' => $authorization])
        );

        // Step 4 – Exchange code for tokens
        $tokens = $this->fetchToken(
            $config['tokenEndpoint'],
            $config['clientId'],
            $config['clientSecret'],
            [
                'code'          => $code,
                'code_verifier' => $verifier,
                'redirect_uri'  => $config['returnUrl'],
                'grant_type'    => 'authorization_code',
            ]
        );

        return $this->buildStore($tokens);
    }

    /**
     * Refresh or validate an existing OAuth store.
     * Returns the store unchanged if the token is still valid; otherwise
     * performs a token refresh.  Throws if the refresh fails (new hCaptcha
     * login required).
     *
     * @throws \RuntimeException if refresh fails
     */
    public function loginWithStore(array $store): array
    {
        if (!empty($store['expires_at']) && (int) $store['expires_at'] > time() + 60) {
            return $store; // token still valid
        }

        if (empty($store['refresh_token'])) {
            throw new \RuntimeException(
                'Kein Refresh-Token vorhanden. Neuer hCaptcha-Token für erneuten Login erforderlich.'
            );
        }

        try {
            $config = $this->fetchOAuthConfig();
            $tokens = $this->fetchToken(
                $config['tokenEndpoint'],
                $config['clientId'],
                $config['clientSecret'],
                [
                    'scope'         => implode(' ', (array) $config['scopes']),
                    'redirect_uri'  => $config['returnUrl'],
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $store['refresh_token'],
                ]
            );

            $newStore = $this->buildStore($tokens);
            // Preserve gcid if the refresh response omits it
            if (empty($newStore['gcid']) && !empty($store['gcid'])) {
                $newStore['gcid'] = $store['gcid'];
            }
            return $newStore;
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Token-Refresh fehlgeschlagen – neuer hCaptcha-Token benötigt. Fehler: ' . $e->getMessage()
            );
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function fetchOAuthConfig(): array
    {
        $url    = $this->server . OAUTH_CONFIG_PATH;
        $result = $this->requestWithRetry('GET', $url, ['ocp-apim-subscription-key' => $this->ocpKey]);
        $this->assertHttpCode($result, 200, 'OAuth-Config');
        $data = $this->decodeJson($result['body'], 'OAuth-Config');

        foreach (['clientId', 'clientSecret', 'tokenEndpoint', 'scopes', 'returnUrl'] as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("OAuth-Config: Pflichtfeld '$field' fehlt.");
            }
        }
        return $data;
    }

    private function postAuthenticate(string $url, array $params, array $extraHeaders = []): array
    {
        $result = $this->requestWithRetry('POST', $url, $extraHeaders, $params);
        $this->assertHttpCode($result, 200, 'Authenticate (Schritt 2)');
        return $this->decodeJson($result['body'], 'Authenticate');
    }

    private function authenticateForCode(string $url, array $params): string
    {
        $queryUrl = $url . '?' . http_build_query([
            'interaction-id' => self::uuid4(),
            'client-version' => BMW_X_USER_AGENT,
        ]);

        // Do NOT follow the redirect – we need the Location header
        $result = $this->curlRequest('POST', $queryUrl, [], $params, false);

        if ($result['http_code'] !== 302 && $result['http_code'] !== 301) {
            throw new \RuntimeException(
                'Authenticate (Schritt 3): Kein Redirect erhalten (HTTP ' . $result['http_code'] . ').'
            );
        }

        if (!preg_match('/^Location:\s*(.+)$/mi', $result['headers'], $m)) {
            throw new \RuntimeException('Authenticate (Schritt 3): Kein Location-Header in Antwort.');
        }

        $locationUrl = trim($m[1]);
        parse_str((string) parse_url($locationUrl, PHP_URL_QUERY), $q);
        $code = $q['code'] ?? '';

        if ($code === '') {
            throw new \RuntimeException(
                'Authenticate (Schritt 3): Kein code-Parameter in Location-URL: ' . $locationUrl
            );
        }
        return $code;
    }

    private function fetchToken(string $url, string $clientId, string $clientSecret, array $params): array
    {
        $result = $this->requestWithRetry(
            'POST', $url, [], $params,
            basicAuth: $clientId . ':' . $clientSecret
        );
        $this->assertHttpCode($result, 200, 'Token-Abruf');
        $data = $this->decodeJson($result['body'], 'Token');

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Token-Antwort enthält kein access_token.');
        }
        return $data;
    }

    private function buildStore(array $tokenResponse): array
    {
        return [
            'access_token'  => $tokenResponse['access_token'],
            'refresh_token' => $tokenResponse['refresh_token'] ?? '',
            'gcid'          => $tokenResponse['gcid'] ?? '',
            'expires_at'    => time() + (int) ($tokenResponse['expires_in'] ?? 3600),
        ];
    }

    // ─── HTTP layer ───────────────────────────────────────────────────────────

    private function requestWithRetry(
        string  $method,
        string  $url,
        array   $extraHeaders = [],
        array   $body         = [],
        ?string $basicAuth    = null
    ): array {
        $delays = [2, 4];

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            $result = $this->curlRequest($method, $url, $extraHeaders, $body, true, $basicAuth);

            if ($result['http_code'] !== 429) {
                return $result;
            }
            if ($attempt < 2) {
                sleep($delays[$attempt]);
            }
        }

        throw new \RuntimeException("HTTP 429 Too Many Requests nach 3 Versuchen: $url");
    }

    private function curlRequest(
        string  $method,
        string  $url,
        array   $extraHeaders   = [],
        array   $body           = [],
        bool    $followLocation = true,
        ?string $basicAuth      = null
    ): array {
        $ch = curl_init();

        $headerLines = [];
        foreach (array_merge($this->defaultHeaders(), $extraHeaders) as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => $followLocation,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
        }

        if ($basicAuth !== null) {
            $opts[CURLOPT_USERPWD]  = $basicAuth;
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        }

        curl_setopt_array($ch, $opts);

        $raw        = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new \RuntimeException("cURL-Fehler für $url: $curlError");
        }

        return [
            'http_code' => $httpCode,
            'headers'   => substr($raw, 0, $headerSize),
            'body'      => substr($raw, $headerSize),
        ];
    }

    private function defaultHeaders(): array
    {
        return [
            'user-agent'         => BMW_USER_AGENT,
            'x-user-agent'       => BMW_X_USER_AGENT,
            'bmw-session-id'     => $this->sessionId,
            'x-correlation-id'   => self::uuid4(),
            'bmw-correlation-id' => self::uuid4(),
        ];
    }

    // ─── PKCE / token helpers (public-static for reuse in api.php) ────────────

    public static function generateCodeVerifier(int $length = 86): string
    {
        $bytes = random_bytes((int) ceil($length * 3 / 4));
        return substr(rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='), 0, $length);
    }

    public static function createS256CodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function generateToken(int $length = 22): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    public static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ─── JSON / assertion helpers ─────────────────────────────────────────────

    private function decodeJson(string $body, string $context): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(
                "$context: Ungültige JSON-Antwort: " . substr($body, 0, 300)
            );
        }
        return $data;
    }

    private function assertHttpCode(array $result, int $expected, string $context): void
    {
        if ($result['http_code'] !== $expected) {
            throw new \RuntimeException(
                "$context: HTTP {$result['http_code']} (erwartet $expected). "
                . 'Body: ' . substr($result['body'], 0, 300)
            );
        }
    }
}
