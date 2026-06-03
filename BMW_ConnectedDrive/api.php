<?php

declare(strict_types=1);

if (!defined('BMW_SERVER_ROW')) {
    require_once __DIR__ . '/auth.php';
}

// ─── BMW API Path Constants ───────────────────────────────────────────────────

define('BMW_PATH_VEHICLE_LIST',   '/eadrax-vcs/v5/vehicle-list');
define('BMW_PATH_REMOTE_CMD',     '/eadrax-vrccs/v4/presentation/remote-commands');
define('BMW_PATH_REMOTE_STATUS',  '/eadrax-vrccs/v3/presentation/remote-commands/eventStatus');

// ─── BMWApi ───────────────────────────────────────────────────────────────────

class BMWApi
{
    private string $server;
    private string $accessToken;
    private string $sessionId;

    public function __construct(string $server, string $accessToken)
    {
        $this->server      = rtrim($server, '/');
        $this->accessToken = $accessToken;
        $this->sessionId   = BMWAuth::uuid4();
    }

    // ─── Vehicle data ─────────────────────────────────────────────────────────

    /**
     * Returns a list of vehicles with full state information.
     *
     * @return array[]
     * @throws \RuntimeException on HTTP or parse errors
     */
    public function fetchVehicleList(): array
    {
        $result = $this->requestWithRetry('GET', $this->server . BMW_PATH_VEHICLE_LIST);
        $this->assertHttpCode($result, 200, 'Fahrzeugliste');
        $data = $this->decodeJson($result['body'], 'Fahrzeugliste');
        return is_array($data) ? $data : [];
    }

    // ─── Remote services ──────────────────────────────────────────────────────

    /**
     * Triggers a remote service and returns the eventId for status polling.
     *
     * Supported services: door-lock, door-unlock, climate-now, horn-blow, lights-flash
     *
     * @throws \RuntimeException on HTTP or parse errors
     */
    public function executeRemoteService(string $vin, string $service): string
    {
        $url    = $this->server . BMW_PATH_REMOTE_CMD . '/' . rawurlencode($vin) . '/' . rawurlencode($service);
        $result = $this->requestWithRetry('POST', $url);
        $this->assertHttpCode($result, 200, "Remote-Service '$service'");
        $data = $this->decodeJson($result['body'], "Remote-Service '$service'");
        return (string) ($data['eventId'] ?? '');
    }

    /**
     * Polls the status of a previously started remote service.
     * eventStatus.status: PENDING | DELIVERED | EXECUTED | ERROR
     *
     * @throws \RuntimeException on HTTP or parse errors
     */
    public function getRemoteServiceStatus(string $eventId): array
    {
        $url    = $this->server . BMW_PATH_REMOTE_STATUS . '?eventId=' . rawurlencode($eventId);
        $result = $this->requestWithRetry('GET', $url);
        $this->assertHttpCode($result, 200, 'Remote-Service-Status');
        return $this->decodeJson($result['body'], 'Remote-Service-Status');
    }

    // ─── HTTP layer ───────────────────────────────────────────────────────────

    private function requestWithRetry(string $method, string $url, ?array $body = null): array
    {
        $delays = [2, 4];

        for ($attempt = 0; $attempt <= 2; $attempt++) {
            $result = $this->curlRequest($method, $url, $body);

            if ($result['http_code'] !== 429) {
                return $result;
            }
            if ($attempt < 2) {
                sleep($delays[$attempt]);
            }
        }

        throw new \RuntimeException("HTTP 429 Too Many Requests nach 3 Versuchen: $url");
    }

    private function curlRequest(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($this->defaultHeaders() as $k => $v) {
            $headerLines[] = "$k: $v";
        }

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $body !== null ? http_build_query($body) : '';
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
            'Authorization'      => 'Bearer ' . $this->accessToken,
            'user-agent'         => BMW_USER_AGENT,
            'x-user-agent'       => BMW_X_USER_AGENT,
            'bmw-session-id'     => $this->sessionId,
            'x-correlation-id'   => BMWAuth::uuid4(),
            'bmw-correlation-id' => BMWAuth::uuid4(),
        ];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

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
