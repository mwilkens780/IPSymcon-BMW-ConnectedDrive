<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';

class BMWCarData extends IPSModule
{
    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('client_id',        '');
        $this->RegisterPropertyInteger('update_interval', 300);

        // Register one boolean property per selectable telematic key
        foreach ($this->getKeyMap() as $prop => $def) {
            $this->RegisterPropertyBoolean($prop, $def[5]);
        }

        $this->RegisterAttributeString('oauth_store',      '');
        $this->RegisterAttributeString('container_id',     '');
        $this->RegisterAttributeString('container_keys',   '');
        $this->RegisterAttributeString('pending_auth',     '');
        $this->RegisterAttributeString('vin',              '');
        $this->RegisterAttributeString('rate_limit_until', '0');

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

        // Detect key-selection change and reset container if needed
        $newKeys    = $this->getSelectedTelematicKeys();
        $storedKeys = json_decode($this->ReadAttributeString('container_keys') ?: '[]', true);
        if (!is_array($storedKeys)) {
            $storedKeys = [];
        }

        $sortedNew    = $newKeys;
        $sortedStored = $storedKeys;
        sort($sortedNew);
        sort($sortedStored);

        if ($sortedNew !== $sortedStored) {
            if (!empty($storedKeys)) {
                // Keys changed from a known previous state – delete old container (best-effort)
                $oldContainerId = $this->ReadAttributeString('container_id');
                if ($oldContainerId !== '') {
                    try {
                        $store = $this->readStore();
                        if (!empty($store)) {
                            $store = BMWCarDataAuth::refreshIfNeeded($clientId, $store);
                            $this->saveStore($store);
                            (new BMWCarDataApi($store['access_token']))->deleteContainer($oldContainerId);
                        }
                    } catch (\Exception $e) {
                        // Delete is known-buggy in BMW API; ignore errors
                    }
                }
                // Remove IPS variables for deactivated keys
                $this->removeDeactivatedVariables($storedKeys, $newKeys);
            }
            $this->WriteAttributeString('container_id',   '');
            $this->WriteAttributeString('container_keys', json_encode($newKeys));
        }

        $store = $this->readStore();
        if (empty($store)) {
            $this->SetStatus(202);
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
        $form        = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $pendingAuth = $this->readPendingAuth();
        $store       = $this->readStore();

        // A pending device-code auth is only actionable while it hasn't expired.
        // Without this check a long-dead code (e.g. from weeks ago) would keep being
        // displayed as if it were still valid, since nothing else clears it.
        if (!empty($pendingAuth) && (int) $pendingAuth['expires_at'] <= time()) {
            $this->WriteAttributeString('pending_auth', '');
            $pendingAuth = [];
        }

        $statusElement = null;

        if (!empty($pendingAuth)) {
            $expiresIn   = max(0, (int) $pendingAuth['expires_at'] - time());
            $uriComplete = $pendingAuth['verification_uri_complete'] ?? '';
            $uriBase     = $pendingAuth['verification_uri'] ?? '';
            $userCode    = $pendingAuth['user_code'] ?? '';

            if ($uriComplete !== '') {
                $caption = "ANMELDUNG AUSSTEHEND (noch {$expiresIn}s gueltig)\n\n"
                    . "1. Oeffne diesen Link im Browser (Code bereits enthalten):\n"
                    . "   " . $uriComplete . "\n\n"
                    . "2. Mit BMW-Account bestaetigen\n"
                    . '3. Dann unten auf "2. Anmeldung pruefen" klicken';
            } else {
                $caption = "ANMELDUNG AUSSTEHEND (noch {$expiresIn}s gueltig)\n\n"
                    . "1. Oeffne diesen Link im Browser:\n"
                    . "   " . $uriBase . "\n\n"
                    . "2. Gib dort diesen Code ein:\n"
                    . "   >>> " . $userCode . " <<<\n\n"
                    . "3. Mit BMW-Account bestaetigen\n"
                    . '4. Dann unten auf "2. Anmeldung pruefen" klicken';
            }

            $statusElement = ['type' => 'Label', 'caption' => $caption];
        } elseif (!empty($store)) {
            $exp           = date('d.m.Y H:i', (int) $store['expires_at']);
            $vin           = $this->ReadAttributeString('vin');
            $vinInfo       = $vin !== '' ? "\nFahrzeug-VIN: $vin" : '';
            $statusElement = [
                'type'    => 'Label',
                'caption' => "Angemeldet\nToken gueltig bis: $exp (wird automatisch erneuert)$vinInfo",
            ];
        } elseif (trim($this->ReadPropertyString('client_id')) !== '') {
            $statusElement = [
                'type'    => 'Label',
                'caption' => "Nicht angemeldet.\nSchritt 1: 'Anmeldung starten' klicken, dann dieses Fenster schliessen und wieder oeffnen.",
            ];
        }

        if ($statusElement !== null) {
            array_splice($form['elements'], 1, 0, [$statusElement]);
        }

        return json_encode($form);
    }

    // ─── Public methods ───────────────────────────────────────────────────────

    public function StartDeviceAuth(): void
    {
        $clientId = trim($this->ReadPropertyString('client_id'));
        if ($clientId === '') {
            $this->LogMessage('BMW CarData: Bitte zuerst die Client-ID konfigurieren.', KL_WARNING);
            return;
        }

        try {
            $pending               = BMWCarDataAuth::startDeviceCodeFlow($clientId);
            $pending['expires_at'] = time() + (int) ($pending['expires_in'] ?? 300);
            $this->WriteAttributeString('pending_auth', json_encode($pending));
            $this->SetStatus(203);

            // Log all fields BMW returned for diagnostics
            $debugFields = [];
            foreach ($pending as $k => $v) {
                if ($k === 'code_verifier') {
                    continue;
                }
                $debugFields[] = $k . '=' . (is_string($v) ? '"' . $v . '"' : json_encode($v));
            }
            $this->LogMessage('BMW CarData DEBUG device code response: ' . implode(', ', $debugFields), KL_MESSAGE);

            $uriComplete = $pending['verification_uri_complete'] ?? '';
            if ($uriComplete !== '') {
                $this->LogMessage(
                    "BMW CarData: Anmeldung starten – oeffne direkt (Code enthalten): {$uriComplete}",
                    KL_MESSAGE
                );
            } else {
                $this->LogMessage(
                    "BMW CarData: Anmeldung starten – oeffne {$pending['verification_uri']} "
                    . "und gib Code ein: {$pending['user_code']}",
                    KL_MESSAGE
                );
            }

            $this->ReloadForm();
        } catch (\Exception $e) {
            $this->LogMessage('BMW CarData StartDeviceAuth: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    public function CheckDeviceAuth(): void
    {
        $pending = $this->readPendingAuth();
        if (empty($pending)) {
            $this->LogMessage('BMW CarData: Keine laufende Anmeldung. Bitte "Anmeldung starten" klicken.', KL_WARNING);
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

            if (isset($store['_debug_poll_response'])) {
                $this->LogMessage('BMW CarData DEBUG token response: ' . $store['_debug_poll_response'], KL_MESSAGE);
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
        // Check if rate-limited
        $rateLimitUntil = (int) $this->ReadAttributeString('rate_limit_until');
        if ($rateLimitUntil > time()) {
            $resumeAt = date('d.m.Y H:i', $rateLimitUntil);
            $this->LogMessage("BMW CarData: API-Tageslimit erreicht, naechster Versuch ab $resumeAt.", KL_WARNING);
            return false;
        }

        try {
            $api = $this->createApi();
            $vin = $this->ensureVin($api);

            $containerId = $this->ReadAttributeString('container_id');
            if ($containerId === '') {
                $selectedKeys = $this->getSelectedTelematicKeys();
                $containerId  = $api->ensureContainer($selectedKeys);
                $this->WriteAttributeString('container_id',   $containerId);
                $this->WriteAttributeString('container_keys', json_encode($selectedKeys));
            }

            $basic     = $api->getBasicVehicleData($vin);
            $telematic = $api->getTelematicData($vin, $containerId);

            $this->updateVariables($vin, $basic, $telematic);
            $this->WriteAttributeString('rate_limit_until', '0');
            $this->SetStatus(102);
            return true;
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'HTTP 401') !== false) {
                $this->SetStatus(202);
                $this->WriteAttributeString('oauth_store', '');
            } elseif (strpos($msg, 'HTTP 403') !== false && strpos($msg, 'rate limit') !== false) {
                $midnight = strtotime('tomorrow midnight UTC') + 3600;
                $this->WriteAttributeString('rate_limit_until', (string) $midnight);
                $resumeAt = date('d.m.Y H:i', $midnight);
                $this->LogMessage(
                    "BMW CarData: Tageslimit erreicht. Naechster Versuch: $resumeAt. "
                    . 'Tipp: Nur eine Instanz betreiben, Intervall >= 300s.',
                    KL_WARNING
                );
                $this->SetStatus(102);
                return false;
            } else {
                $this->SetStatus(200);
            }
            $this->LogMessage('BMW CarData FetchVehicleData: ' . $msg, KL_ERROR);
            return false;
        } catch (\Exception $e) {
            $this->LogMessage('BMW CarData FetchVehicleData: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
            return false;
        }
    }

    public function ResetAuth(): void
    {
        $this->WriteAttributeString('oauth_store',      '');
        $this->WriteAttributeString('pending_auth',     '');
        $this->WriteAttributeString('container_id',     '');
        $this->WriteAttributeString('container_keys',   '');
        $this->WriteAttributeString('vin',              '');
        $this->SetTimerInterval('UpdateTimer', 0);
        $this->SetStatus(202);
        $this->LogMessage('BMW CarData: Auth zurueckgesetzt. Bitte erneut anmelden.', KL_MESSAGE);
    }

    // ─── Key map ──────────────────────────────────────────────────────────────

    /**
     * Returns the telematic key definitions.
     * Format per entry: [telematic_key, ident_suffix, display_name, var_type, transform, default_enabled]
     *   var_type:  0=bool  1=int  2=float  3=string
     *   transform: 'bool' 'int' 'float' 'locked' 'string'
     */
    private function getKeyMap(): array
    {
        return [
            // ── General (all vehicle types) ───────────────────────────────────
            'key_mileage'    => ['vehicle.vehicle.travelledDistance',                                          'Mileage',        'Kilometerstand',       1, 'int',    true],
            'key_locked'     => ['vehicle.cabin.door.lock.status',                                             'Locked',         'Verriegelt',           0, 'locked', true],
            'key_door_all'   => ['vehicle.cabin.door.status',                                                  'DoorStatus',     'Tuer-Gesamtstatus',    3, 'string', true],
            'key_lat'        => ['vehicle.cabin.infotainment.navigation.currentLocation.latitude',             'Latitude',       'GPS Breitengrad',      2, 'float',  true],
            'key_lon'        => ['vehicle.cabin.infotainment.navigation.currentLocation.longitude',            'Longitude',      'GPS Laengengrad',      2, 'float',  true],
            'key_heading'    => ['vehicle.cabin.infotainment.navigation.currentLocation.heading',              'Heading',        'Fahrtrichtung (Grad)', 1, 'int',    false],
            'key_batt12v'    => ['vehicle.electricalSystem.battery.stateOfCharge',                            'Battery12V',     '12V-Batterie (%)',     2, 'float',  true],
            'key_nav_range'  => ['vehicle.cabin.infotainment.navigation.remainingRange',                       'NavRange',       'Reichweite (Navi)',    1, 'int',    false],
            // ── Combustion / Hybrid ───────────────────────────────────────────
            'key_fuel_pct'   => ['vehicle.drivetrain.fuelSystem.level',                                        'FuelLevel',      'Kraftstoff (%)',       2, 'float',  true],
            'key_fuel_l'     => ['vehicle.drivetrain.fuelSystem.remainingFuel',                                'FuelLiters',     'Kraftstoff (L)',       2, 'float',  true],
            'key_range_tot'  => ['vehicle.drivetrain.totalRemainingRange',                                     'TotalRange',     'Gesamtreichweite',    1, 'int',    true],
            // ── Electric / PHEV ───────────────────────────────────────────────
            'key_ev_level'   => ['vehicle.drivetrain.electricEngine.charging.level',                           'ChargingLevel',  'Ladestand (%)',        1, 'int',    true],
            'key_ev_status'  => ['vehicle.drivetrain.electricEngine.charging.status',                          'ChargingStatus', 'Ladestatus',           3, 'string', true],
            'key_ev_range'   => ['vehicle.drivetrain.electricEngine.remainingElectricRange',                   'EVRange',        'EV-Reichweite (km)',   1, 'int',    true],
            'key_ev_time'    => ['vehicle.drivetrain.electricEngine.charging.timeToFullyCharged',              'ChargingTime',   'Zeit bis Vollladung',  1, 'int',    true],
            'key_ev_plugged' => ['vehicle.powertrain.tractionBattery.charging.port.anyPosition.isPlugged',     'IsPlugged',      'Ladekabel',            0, 'bool',   true],
            // ── Individual doors & trunk ──────────────────────────────────────
            'key_door_fl'    => ['vehicle.cabin.door.row1.driver.isOpen',                                      'DoorFL',         'Tuer vorne links',     0, 'bool',   false],
            'key_door_fr'    => ['vehicle.cabin.door.row1.passenger.isOpen',                                   'DoorFR',         'Tuer vorne rechts',    0, 'bool',   false],
            'key_door_rl'    => ['vehicle.cabin.door.row2.driver.isOpen',                                      'DoorRL',         'Tuer hinten links',    0, 'bool',   false],
            'key_door_rr'    => ['vehicle.cabin.door.row2.passenger.isOpen',                                   'DoorRR',         'Tuer hinten rechts',   0, 'bool',   false],
            'key_trunk'      => ['vehicle.body.trunk.door.isOpen',                                             'Trunk',          'Kofferraum',           0, 'bool',   false],
            // ── Windows ───────────────────────────────────────────────────────
            'key_win_fl'     => ['vehicle.cabin.window.row1.driver.status',                                    'WindowFL',       'Fenster vorne links',  3, 'string', false],
            'key_win_fr'     => ['vehicle.cabin.window.row1.passenger.status',                                 'WindowFR',       'Fenster vorne rechts', 3, 'string', false],
            'key_win_rl'     => ['vehicle.cabin.window.row2.driver.status',                                    'WindowRL',       'Fenster hinten links', 3, 'string', false],
            'key_win_rr'     => ['vehicle.cabin.window.row2.passenger.status',                                 'WindowRR',       'Fenster hinten rechts',3, 'string', false],
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function getSelectedTelematicKeys(): array
    {
        $keys = [];
        foreach ($this->getKeyMap() as $prop => $def) {
            if ($this->ReadPropertyBoolean($prop)) {
                $keys[] = $def[0];
            }
        }
        return $keys;
    }

    private function removeDeactivatedVariables(array $oldKeys, array $newKeys): void
    {
        $vin = $this->ReadAttributeString('vin');
        if ($vin === '') {
            return;
        }

        $removed = array_diff($oldKeys, $newKeys);
        if (empty($removed)) {
            return;
        }

        foreach ($this->getKeyMap() as $prop => $def) {
            if (!in_array($def[0], $removed, true)) {
                continue;
            }
            $varId = @IPS_GetObjectIDByIdent($vin . '_' . $def[1], $this->InstanceID);
            if ($varId !== false) {
                IPS_DeleteVariable($varId);
            }
        }
    }

    private function createApi(): BMWCarDataApi
    {
        $clientId = trim($this->ReadPropertyString('client_id'));
        $store    = $this->readStore();

        if (empty($store)) {
            throw new \RuntimeException('Nicht angemeldet. Bitte "Anmeldung starten" klicken.');
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
        // Always: model name from basicData
        $model = ($basic['modelName'] ?? '') ?: ($basic['model'] ?? '');
        if ($model !== '') {
            $this->setVar($vin . '_Model', 'Modell', VARIABLETYPE_STRING, $model);
        }

        // Key-map driven telematic variables
        foreach ($this->getKeyMap() as $prop => [$teleKey, $identSuffix, $displayName, $varType, $transform]) {
            if (!$this->ReadPropertyBoolean($prop)) {
                continue;
            }

            $raw = $telematic[$teleKey]['value'] ?? null;
            if ($raw === null) {
                continue;
            }

            $value = $this->transformValue($transform, $raw);
            $this->setVar($vin . '_' . $identSuffix, $displayName, $varType, $value);
        }

        // Always: last update
        $this->setVar($vin . '_LastUpdate', 'Letztes Update', VARIABLETYPE_STRING, date('c'));
    }

    private function transformValue(string $transform, $raw)
    {
        switch ($transform) {
            case 'int':    return (int) $raw;
            case 'float':  return (float) $raw;
            case 'bool':   return (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            case 'locked': return strtoupper((string) $raw) === 'SECURED';
            default:       return (string) $raw;
        }
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
