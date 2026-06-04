<?php

declare(strict_types=1);

if (!defined('CARDATA_API_BASE')) {
    require_once __DIR__ . '/auth.php';
}

// ─── Telematic keys to include in the data container ─────────────────────────

define('CARDATA_CONTAINER_NAME', 'IPSymcon_BMWCD');

define('CARDATA_TELEMATIC_KEYS', [
    // Mileage
    'vehicle.vehicle.travelledDistance',
    // Fuel
    'vehicle.drivetrain.fuelSystem.level',
    'vehicle.drivetrain.fuelSystem.remainingFuel',
    // Range
    'vehicle.drivetrain.totalRemainingRange',
    'vehicle.drivetrain.electricEngine.remainingElectricRange',
    'vehicle.cabin.infotainment.navigation.remainingRange',
    // EV / Charging
    'vehicle.drivetrain.electricEngine.charging.level',
    'vehicle.drivetrain.electricEngine.charging.status',
    'vehicle.drivetrain.electricEngine.charging.timeToFullyCharged',
    'vehicle.powertrain.tractionBattery.charging.port.anyPosition.isPlugged',
    // Location
    'vehicle.cabin.infotainment.navigation.currentLocation.latitude',
    'vehicle.cabin.infotainment.navigation.currentLocation.longitude',
    'vehicle.cabin.infotainment.navigation.currentLocation.heading',
    // Doors & Lock
    'vehicle.cabin.door.lock.status',
    'vehicle.cabin.door.status',
    'vehicle.cabin.door.row1.driver.isOpen',
    'vehicle.cabin.door.row1.passenger.isOpen',
    'vehicle.cabin.door.row2.driver.isOpen',
    'vehicle.cabin.door.row2.passenger.isOpen',
    'vehicle.body.trunk.door.isOpen',
    // 12V battery
    'vehicle.electricalSystem.battery.stateOfCharge',
]);

// ─── BMWCarDataApi ────────────────────────────────────────────────────────────

class BMWCarDataApi
{
    private string $accessToken;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    // ─── Vehicle endpoints ────────────────────────────────────────────────────

    /** Returns array of ['vin' => ..., 'type' => 'PRIMARY'|'SECONDARY', ...] */
    public function getVehicleMappings(): array
    {
        return $this->get('/customers/vehicles/mappings');
    }

    public function getBasicVehicleData(string $vin): array
    {
        return $this->get('/customers/vehicles/' . rawurlencode($vin) . '/basicData');
    }

    /** Returns key→{value,unit,timestamp} map from the container. */
    public function getTelematicData(string $vin, string $containerId): array
    {
        $data = $this->get(
            '/customers/vehicles/' . rawurlencode($vin) . '/telematicData',
            ['containerId' => $containerId]
        );
        return $data['telematicData'] ?? [];
    }

    // ─── Container management ─────────────────────────────────────────────────

    public function getContainers(): array
    {
        return $this->get('/customers/containers');
    }

    /** Creates a container and returns its ID. */
    public function createContainer(string $name, array $keys): string
    {
        $response = $this->post('/customers/containers', [
            'name'                 => $name,
            'purpose'              => 'IP-Symcon home automation',
            'technicalDescriptors' => $keys,
        ]);
        $id = $response['id'] ?? '';
        if ($id === '') {
            throw new \RuntimeException('Container erstellt, aber keine ID in Antwort.');
        }
        return $id;
    }

    public function deleteContainer(string $containerId): void
    {
        $this->delete('/customers/containers/' . rawurlencode($containerId));
    }

    /** Returns existing IPSymcon container ID or creates a new one. */
    public function ensureContainer(): string
    {
        $containers = $this->getContainers();
        foreach ($containers as $c) {
            if (($c['name'] ?? '') === CARDATA_CONTAINER_NAME) {
                return (string) $c['id'];
            }
        }
        return $this->createContainer(CARDATA_CONTAINER_NAME, CARDATA_TELEMATIC_KEYS);
    }

    // ─── HTTP helpers ─────────────────────────────────────────────────────────

    private function get(string $path, array $query = []): array
    {
        $url = CARDATA_API_BASE . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', CARDATA_API_BASE . $path, $body);
    }

    private function delete(string $path): void
    {
        $this->request('DELETE', CARDATA_API_BASE . $path);
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'x-version: v1',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $headers[]                = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt_array($ch, $opts);
        // Re-set headers after potential body update
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw       = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new \RuntimeException("cURL-Fehler: $curlError");
        }

        if ($httpCode === 204) {
            return []; // DELETE success, no body
        }

        if ($httpCode === 401) {
            throw new \RuntimeException('HTTP 401 – Token abgelaufen oder ungültig.');
        }

        if ($httpCode >= 400) {
            $err = json_decode($raw, true);
            $msg = $err['exveErrorMsg'] ?? substr($raw, 0, 200);
            throw new \RuntimeException("HTTP $httpCode: $msg");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Ungültige JSON-Antwort: ' . substr($raw, 0, 200));
        }
        return $data;
    }
}
