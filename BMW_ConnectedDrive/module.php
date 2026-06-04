<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';

class BMWConnectedDrive extends IPSModule
{
    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('client_id',       '');
        $this->RegisterPropertyInteger('update_interval', 300);

        // OAuth store: access_token, refresh_token, expires_at
        $this->RegisterAttributeString('oauth_store', '');
        // Container ID for telematic data
        $this->RegisterAttributeString('container_id', '');
        // Device Code Flow pending state
        $this->RegisterAttributeString('pending_auth', '');
        // Primary VIN (auto-detected)
        $this->RegisterAttributeString('vin', '');

        $this->RegisterTimer('UpdateTimer', 0, 'BMWCD_FetchVehicleData($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $clientId = trim($this->ReadPropertyString('client_id'));
        if ($clientId === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $store = $this->readStore();
        if (empty($store)) {
            $this->SetStatus(202); // auth required
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);
    }

    // ─── Dynamic configuration form ───────────────────────────────────────────

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $pendingAuth = $this->readPendingAuth();
        $store       = $this->readStore();

        // Insert auth status element below the client_id field
        $statusElement = null;

        if (!empty($pendingAuth)) {
            $expiresIn = max(0, (int) $pendingAuth['expires_at'] - time());
            $statusElement = [
                'type'    => 'Label',
                'caption' => "⏳ Warte auf Browser-Anmeldung (noch {$expiresIn}s gültig)\n"
                    . "1. Öffne: " . $pendingAuth['verification_uri'] . "\n"
                    . "2. Gib diesen Code ein: " . $pendingAuth['user_code'] . "\n"
                    . "3. Klicke dann auf „Anmeldung prüfen"",
            ];
        } elseif (!empty($store)) {
            $exp = date('d.m.Y H:i', (int) $store['expires_at']);
            $vin = $this->ReadAttributeString('vin');
            $vinInfo = $vin !== '' ? " | VIN: $vin" : '';
            $statusElement = [
                'type'    => 'Label',
                'caption' => "✅ Angemeldet – Token gültig bis $exp$vinInfo",
            ];
        } else {
            $clientId = trim($this->ReadPropertyString('client_id'));
            if ($clientId !== '') {
                $statusElement = [
                    'type'    => 'Label',
                    'caption' => "⚠️ Nicht angemeldet – klicke auf „Anmeldung starten"",
                ];
            }
        }

        if ($statusElement !== null) {
            array_splice($form['elements'], 1, 0, [$statusElement]);
        }

        return json_encode($form);
    }

    // ─── Public methods ───────────────────────────────────────────────────────

    /** Step 1: Starts Device Code Flow, shows code/URL in form */
    public function StartDeviceAuth(): void
    {
        $clientId = trim($this->ReadPropertyString('client_id'));
        if ($clientId === '') {
            $this->LogMessage('BMW CarData: Bitte zuerst die Client-ID konfigurieren.', KL_WARNING);
            return;
        }

        try {
            $pending = BMWCarDataAuth::startDeviceCodeFlow($clientId);
            $pending['expires_at'] = time() + (int) ($pending['expires_in'] ?? 300);
            $this->WriteAttributeString('pending_auth', json_encode($pending));
            $this->SetStatus(203);

            $this->LogMessage(
                "BMW CarData: Anmeldung starten – öffne {$pending['verification_uri']} "
                . "und gib Code ein: {$pending['user_code']}",
                KL_MESSAGE
            );
        } catch (\Exception $e) {
            $this->LogMessage('BMW CarData StartDeviceAuth: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    /** Step 2: Polls once for the token; call after completing browser auth */
    public function CheckDeviceAuth(): void
    {
        $pending = $this->readPendingAuth();
        if (empty($pending)) {
            $this->LogMessage('BMW CarData: Keine laufende Anmeldung. Bitte „Anmeldung starten" klicken.', KL_WARNING);
            return;
        }

        $clientId = trim($this->ReadPropertyString('client_id'));

        try {
            $store = BMWCarDataAuth::pollForToken(
                $clientId,
                $pending['device_code'],
                $pending['code_verifier']
            );

            if ($store === null) {
                $remaining = max(0, (int) $pending['expires_at'] - time());
                $this->LogMessage(
                    "BMW CarData: Noch nicht autorisiert. Code bitte eingeben (noch {$remaining}s).",
                    KL_MESSAGE
                );
                return;
            }

            $this->saveStore($store);
            $this->WriteAttributeString('pending_auth', '');
            $this->SetStatus(102);

            $interval = $this->ReadPropertyInteger('update_interval');
            $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);

            $this->LogMessage('BMW CarData: Anmeldung erfolgreich. Fahrzeugdaten werden geladen...', KL_MESSAGE);
            $this->FetchVehicleData();
        } catch (\Exception $e) {
            $this->LogMessage('BMW CarData CheckDeviceAuth: ' . $e->getMessage(), KL_ERROR);
            $this->WriteAttributeString('pending_auth', '');
            $this->SetStatus(200);
        }
    }

    public function FetchVehicleData(): bool
    {
        try {
            $api = $this->createApi();
            $vin = $this->ensureVin($api);

            // Ensure telematic container exists
            $containerId = $this->ReadAttributeString('container_id');
            if ($containerId === '') {
                $containerId = $api->ensureContainer();
                $this->WriteAttributeString('container_id', $containerId);
            }

            // Fetch basic + telematic data
            $basic    = $api->getBasicVehicleData($vin);
            $telematic = $api->getTelematicData($vin, $containerId);

            $this->updateVariables($vin, $basic, $telematic);
            $this->SetStatus(102);
            return true;
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), 'HTTP 401') !== false) {
                $this->SetStatus(202);
                $this->WriteAttributeString('oauth_store', '');
            } else {
                $this->SetStatus(200);
            }
            $this->LogMessage('BMW FetchVehicleData: ' . $e->getMessage(), KL_ERROR);
            return false;
        } catch (\Exception $e) {
            $this->LogMessage('BMW FetchVehicleData: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
            return false;
        }
    }

    public function ResetAuth(): void
    {
        $this->WriteAttributeString('oauth_store', '');
        $this->WriteAttributeString('pending_auth', '');
        $this->WriteAttributeString('container_id', '');
        $this->WriteAttributeString('vin', '');
        $this->SetTimerInterval('UpdateTimer', 0);
        $this->SetStatus(202);
        $this->LogMessage('BMW CarData: Auth zurückgesetzt. Bitte erneut anmelden.', KL_MESSAGE);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function createApi(): BMWCarDataApi
    {
        $clientId = trim($this->ReadPropertyString('client_id'));
        $store    = $this->readStore();

        if (empty($store)) {
            throw new \RuntimeException('Nicht angemeldet. Bitte „Anmeldung starten" klicken.');
        }

        $store = BMWCarDataAuth::refreshIfNeeded($clientId, $store);
        $this->saveStore($store);
        return new BMWCarDataApi($store['access_token']);
    }

    private function ensureVin(BMWCarDataApi $api): string
    {
        $vin = $this->ReadAttributeString('vin');
        if ($vin !== '') {
            return $vin;
        }

        $mappings = $api->getVehicleMappings();
        foreach ($mappings as $m) {
            if (($m['type'] ?? '') === 'PRIMARY' && !empty($m['vin'])) {
                $vin = $m['vin'];
                break;
            }
        }
        if ($vin === '' && !empty($mappings[0]['vin'])) {
            $vin = $mappings[0]['vin'];
        }
        if ($vin === '') {
            throw new \RuntimeException('Kein Fahrzeug im BMW-Account gefunden.');
        }

        $this->WriteAttributeString('vin', $vin);
        return $vin;
    }

    private function updateVariables(string $vin, array $basic, array $telematic): void
    {
        // Basic vehicle data
        $model = ($basic['modelName'] ?? '') ?: ($basic['model'] ?? '');
        if ($model !== '') {
            $this->setVar($vin . '_Model', 'Modell', VARIABLETYPE_STRING, $model);
        }

        // Helper to extract telematic value
        $t = function (string $key) use ($telematic) {
            return isset($telematic[$key]['value']) ? $telematic[$key]['value'] : null;
        };

        // Mileage
        $val = $t('vehicle.vehicle.travelledDistance');
        if ($val !== null) {
            $this->setVar($vin . '_Mileage', 'Kilometerstand', VARIABLETYPE_INTEGER, (int) $val);
        }

        // Fuel
        $val = $t('vehicle.drivetrain.fuelSystem.level');
        if ($val !== null) {
            $this->setVar($vin . '_FuelLevel', 'Kraftstoff (%)', VARIABLETYPE_FLOAT, (float) $val);
        }
        $val = $t('vehicle.drivetrain.fuelSystem.remainingFuel');
        if ($val !== null) {
            $this->setVar($vin . '_RemainingFuel', 'Kraftstoff (L)', VARIABLETYPE_FLOAT, (float) $val);
        }

        // Range
        $val = $t('vehicle.drivetrain.totalRemainingRange');
        if ($val !== null) {
            $this->setVar($vin . '_TotalRange', 'Gesamtreichweite (km)', VARIABLETYPE_INTEGER, (int) $val);
        }
        $val = $t('vehicle.drivetrain.electricEngine.remainingElectricRange');
        if ($val !== null) {
            $this->setVar($vin . '_EVRange', 'Elektrische Reichweite (km)', VARIABLETYPE_INTEGER, (int) $val);
        }

        // EV Charging
        $val = $t('vehicle.drivetrain.electricEngine.charging.level');
        if ($val !== null) {
            $this->setVar($vin . '_ChargingLevel', 'Ladestand (%)', VARIABLETYPE_INTEGER, (int) $val);
        }
        $val = $t('vehicle.drivetrain.electricEngine.charging.status');
        if ($val !== null) {
            $this->setVar($vin . '_ChargingStatus', 'Ladestatus', VARIABLETYPE_STRING, (string) $val);
        }
        $val = $t('vehicle.drivetrain.electricEngine.charging.timeToFullyCharged');
        if ($val !== null) {
            $this->setVar($vin . '_ChargingTime', 'Zeit bis Vollladung (min)', VARIABLETYPE_INTEGER, (int) $val);
        }
        $val = $t('vehicle.powertrain.tractionBattery.charging.port.anyPosition.isPlugged');
        if ($val !== null) {
            $this->setVar($vin . '_IsPlugged', 'Ladekabel', VARIABLETYPE_BOOLEAN, filter_var($val, FILTER_VALIDATE_BOOLEAN));
        }

        // Location
        $lat = $t('vehicle.cabin.infotainment.navigation.currentLocation.latitude');
        $lon = $t('vehicle.cabin.infotainment.navigation.currentLocation.longitude');
        if ($lat !== null) {
            $this->setVar($vin . '_Latitude', 'Breitengrad', VARIABLETYPE_FLOAT, (float) $lat);
        }
        if ($lon !== null) {
            $this->setVar($vin . '_Longitude', 'Längengrad', VARIABLETYPE_FLOAT, (float) $lon);
        }

        // Doors & Lock
        $val = $t('vehicle.cabin.door.lock.status');
        if ($val !== null) {
            $this->setVar($vin . '_Locked', 'Verriegelt', VARIABLETYPE_BOOLEAN, strtoupper((string) $val) === 'SECURED');
        }

        // 12V battery
        $val = $t('vehicle.electricalSystem.battery.stateOfCharge');
        if ($val !== null) {
            $this->setVar($vin . '_Battery12V', '12V Batterie (%)', VARIABLETYPE_FLOAT, (float) $val);
        }

        // Last update timestamp
        $this->setVar($vin . '_LastUpdate', 'Letztes Update', VARIABLETYPE_STRING, date('c'));
    }

    private function setVar(string $ident, string $name, int $type, $value): void
    {
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            switch ($type) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean($ident, $name);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $name);
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $name);
                    break;
                default:
                    $this->RegisterVariableString($ident, $name);
            }
        }
        $this->SetValue($ident, $value);
    }

    private function readStore(): array
    {
        $json = $this->ReadAttributeString('oauth_store');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function saveStore(array $store): void
    {
        $this->WriteAttributeString('oauth_store', json_encode($store));
    }

    private function readPendingAuth(): array
    {
        $json = $this->ReadAttributeString('pending_auth');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
