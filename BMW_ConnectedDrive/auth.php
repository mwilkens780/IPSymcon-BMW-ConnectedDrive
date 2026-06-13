<?php

declare(strict_types=1);

// ─── CarData API Constants ────────────────────────────────────────────────────

define('CARDATA_AUTH_BASE',          'https://customer.bmwgroup.com');
define('CARDATA_API_BASE',           'https://api-cardata.bmwgroup.com');
define('CARDATA_SCOPE',              'authenticate_user openid cardata:api:read cardata:streaming:read');
define('CARDATA_DEVICE_CODE_PATH',   '/gcdm/oauth/device/code');
define('CARDATA_TOKEN_PATH',         '/gcdm/oauth/token');

// ─── BMWCarDataAuth ───────────────────────────────────────────────────────────

class BMWCarDataAuth
{
    /**
     * Step 1 of Device Code Flow.
     * Returns: device_code, user_code, verification_uri, expires_in, interval, code_verifier
     */
    public static function startDeviceCodeFlow(string $clientId): array
    {
        $verifier  = self::generateCodeVerifier();
        $challenge = self::createS256CodeChallenge($verifier);

        $result = self::httpPost(CARDATA_AUTH_BASE . CARDATA_DEVICE_CODE_PATH, [
            'client_id'             => $clientId,
            'response_type'         => 'device_code',
            'scope'                 => CARDATA_SCOPE,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        if (isset($result['error'])) {
            throw new \RuntimeException(
                'Device Code Flow fehlgeschlagen: ' . $result['error']
                . ' – ' . ($result['error_description'] ?? '')
            );
        }

        foreach (['device_code', 'user_code', 'verification_uri'] as $field) {
            if (empty($result[$field])) {
                throw new \RuntimeException("Device Code Response: Feld '$field' fehlt.");
            }
        }

        return array_merge($result, ['code_verifier' => $verifier]);
    }

    /**
     * Step 2 of Device Code Flow – call repeatedly until user authorised.
     * Returns null while still pending, OAuth store array on success.
     * Throws on hard errors (expired, denied, invalid).
     */
    public static function pollForToken(string $clientId, string $deviceCode, string $codeVerifier): ?array
    {
        $result = self::httpPost(CARDATA_AUTH_BASE . CARDATA_TOKEN_PATH, [
            'grant_type'    => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code'   => $deviceCode,
            'client_id'     => $clientId,
            'code_verifier' => $codeVerifier,
        ]);

        if (isset($result['error'])) {
            if ($result['error'] === 'authorization_pending' || $result['error'] === 'slow_down') {
                return null; // still waiting
            }
            throw new \RuntimeException(
                'Token-Polling fehlgeschlagen: ' . $result['error']
                . ' – ' . ($result['error_description'] ?? '')
            );
        }

        if (empty($result['access_token'])) {
            throw new \RuntimeException('Token-Antwort enthält kein access_token.');
        }

        return self::buildStore($result);
    }

    /**
     * Refreshes an existing token store. Returns the store unchanged when still valid.
     * Throws if the refresh token is expired or missing.
     */
    public static function refreshIfNeeded(string $clientId, array $store): array
    {
        if (!empty($store['expires_at']) && (int) $store['expires_at'] > time() + 60) {
            return $store;
        }

        if (empty($store['refresh_token'])) {
            throw new \RuntimeException('Kein Refresh-Token – erneute Anmeldung erforderlich.');
        }

        $result = self::httpPost(CARDATA_AUTH_BASE . CARDATA_TOKEN_PATH, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $store['refresh_token'],
            'client_id'     => $clientId,
            'scope'         => CARDATA_SCOPE,
        ]);

        if (isset($result['error'])) {
            throw new \RuntimeException(
                'Token-Refresh fehlgeschlagen: ' . $result['error']
                . ' – ' . ($result['error_description'] ?? '')
            );
        }

        if (empty($result['access_token'])) {
            throw new \RuntimeException('Refresh-Antwort enthält kein access_token.');
        }

        return self::buildStore($result);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private static function buildStore(array $response): array
    {
        return [
            'access_token'  => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? '',
            'expires_at'    => time() + (int) ($response['expires_in'] ?? 3600),
        ];
    }

    private static function httpPost(string $url, array $params): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException("cURL-Fehler: $error");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Ungültige JSON-Antwort: ' . substr($body, 0, 200));
        }
        return $data;
    }

    // ─── PKCE helpers ─────────────────────────────────────────────────────────

    public static function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function createS256CodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
