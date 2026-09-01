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
        $this->RegisterPropertyInteger('update_interval', 1800);

        // Register one boolean property per selectable telematic key
        foreach ($this->getKeyMap() as $prop => $def) {
            $this->RegisterPropertyBoolean($prop, $def[5]);
        }

        $this->RegisterAttributeString('oauth_store',      '');
        $this->RegisterAttributeString('container_id',     '');
        $this->RegisterAttributeString('container_keys',   '');
        $this->RegisterAttributeString('pending_auth',     '');
        $this->RegisterAttributeString('vin',              '');
        $this->RegisterAttributeString('model_name',       '');
        $this->RegisterAttributeString('rate_limit_until', '0');

        $this->RegisterTimer('UpdateTimer', 0, 'BMWCD_FetchVehicleData($_IPS[\'TARGET\']);');

        // HTML-SDK dashboard tile (top-down car graphic + stat tiles)
        $this->SetVisualizationType(1);
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

    // ─── HTML-SDK: dashboard tile ──────────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildDashboardHTML();
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
        // Check if rate-limited. Not logging here — the transition into this
        // state already logs a clear message below; re-logging on every poll
        // while waiting (every 30 min, for up to ~22h) just floods the log
        // with a message that never changes.
        $rateLimitUntil = (int) $this->ReadAttributeString('rate_limit_until');
        if ($rateLimitUntil > time()) {
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

            // basicData only ever supplies the model name, which never changes
            // for a given VIN — fetch it once and cache it instead of spending
            // half the (very tight) daily API quota on it every single poll.
            $model = $this->ReadAttributeString('model_name');
            if ($model === '') {
                $basic = $api->getBasicVehicleData($vin);
                $model = ($basic['modelName'] ?? '') ?: ($basic['model'] ?? '');
                if ($model !== '') {
                    $this->WriteAttributeString('model_name', $model);
                }
            }

            $telematic = $api->getTelematicData($vin, $containerId);

            $this->updateVariables($vin, $model, $telematic);
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
        $this->WriteAttributeString('model_name',       '');
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
            'key_heading'    => ['vehicle.cabin.infotainment.navigation.currentLocation.heading',              'Heading',        'Fahrtrichtung (Grad)', 1, 'int',    true],
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
            'key_door_fl'    => ['vehicle.cabin.door.row1.driver.isOpen',                                      'DoorFL',         'Tuer vorne links',     0, 'bool',   true],
            'key_door_fr'    => ['vehicle.cabin.door.row1.passenger.isOpen',                                   'DoorFR',         'Tuer vorne rechts',    0, 'bool',   true],
            'key_door_rl'    => ['vehicle.cabin.door.row2.driver.isOpen',                                      'DoorRL',         'Tuer hinten links',    0, 'bool',   true],
            'key_door_rr'    => ['vehicle.cabin.door.row2.passenger.isOpen',                                   'DoorRR',         'Tuer hinten rechts',   0, 'bool',   true],
            'key_trunk'      => ['vehicle.body.trunk.door.isOpen',                                             'Trunk',          'Kofferraum',           0, 'bool',   true],
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

    private function updateVariables(string $vin, string $model, array $telematic): void
    {
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
        $varId = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varId === false) {
            $this->createVariable($ident, $name, $type);
        } elseif ((int) IPS_GetVariable($varId)['VariableType'] !== $type) {
            // A variable created by an older module version can end up with
            // the wrong type (found live 01.09.2026: "ChargingTime" existed
            // as Boolean, showing "On"/"Off" instead of minutes, from a
            // schema that predates the current key map). IPS has no
            // in-place type change, only delete + recreate.
            IPS_DeleteVariable($varId);
            $this->createVariable($ident, $name, $type);
        }
        $this->SetValue($ident, $value);
        $this->pushValue($ident, $value);
    }

    private function createVariable(string $ident, string $name, int $type): void
    {
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

    /**
     * Push a live value update to an already-open dashboard tile.
     * Received client-side by window.handleMessage({key, value}) in
     * buildDashboardHTML(). Strips the "<vin>_" prefix off the ident so the
     * tile's JS can key off the same short names used when building the
     * initial HTML (e.g. "Locked", "ChargingLevel").
     */
    private function pushValue(string $ident, $value): void
    {
        $vin = $this->ReadAttributeString('vin');
        $key = ($vin !== '' && strpos($ident, $vin . '_') === 0) ? substr($ident, strlen($vin) + 1) : $ident;
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
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

    // ─── HTML-SDK dashboard tile ────────────────────────────────────────────────

    /** Reads a telematic variable's current value, or null if it doesn't exist (key disabled or never returned data by BMW). */
    private function readVal(string $vin, string $identSuffix)
    {
        if ($vin === '') {
            return null;
        }
        $id = @IPS_GetObjectIDByIdent($vin . '_' . $identSuffix, $this->InstanceID);
        if ($id === false) {
            return null;
        }
        return GetValue($id);
    }

    /**
     * BMW doesn't expose an explicit "cable plugged in" flag that returns data
     * for every vehicle (see project notes) — ChargingStatus is the reliable
     * signal, so infer a coarse plugged/charging state from it instead.
     * Returns [cssClass, germanLabel].
     */
    private function classifyChargingStatus(?string $status): array
    {
        if ($status === null || $status === '') {
            return ['badge-off', 'Unbekannt'];
        }
        $s = strtoupper($status);
        // Terminal/finished states must be checked before the generic
        // "CHARGING" substring match below -- BMW's real-world value
        // "CHARGINGENDED" (confirmed live 01.09.2026) contains "CHARGING"
        // and would otherwise be misread as still actively charging.
        if (strpos($s, 'ENDED') !== false || strpos($s, 'FINISHED') !== false || strpos($s, 'TARGET') !== false || strpos($s, 'COMPLETE') !== false) {
            return ['badge-on', 'Vollstaendig geladen'];
        }
        if (strpos($s, 'NOCHARGING') !== false || strpos($s, 'NOT_CHARGING') !== false || strpos($s, 'INVALID') !== false) {
            return ['badge-off', 'Kein Ladevorgang'];
        }
        if (strpos($s, 'CHARGING') !== false) {
            return ['badge-green', 'Laedt'];
        }
        if (strpos($s, 'WAIT') !== false || strpos($s, 'PLUG') !== false) {
            return ['badge-amber', 'Angeschlossen'];
        }
        return ['badge-off', $status];
    }

    private function windowClass(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'door-unknown';
        }
        return strtoupper($status) === 'CLOSED' ? 'door-closed' : 'door-open';
    }

    private function doorClass($open): string
    {
        if ($open === null) {
            return 'door-unknown';
        }
        return $open ? 'door-open' : 'door-closed';
    }

    private function formatMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h > 0 ? "{$h}h {$m}min" : "{$m}min";
    }

    /**
     * OpenStreetMap embed (no API key needed) centered on the vehicle's last
     * known position. The heading arrow is a plain rotated element overlaid
     * on top of the iframe -- the iframe itself can't be scripted cross-origin.
     */
    private function renderMapBlock($lat, $lon, $heading): string
    {
        if ($lat === null || $lon === null) {
            return '';
        }

        $latF = (float) $lat;
        $lonF = (float) $lon;
        $d    = 0.004;
        // sprintf with '.' forced -- (string) cast would use the server locale's
        // decimal separator (e.g. "53,72" in de_DE), breaking the bbox param.
        $bbox = sprintf('%.6F,%.6F,%.6F,%.6F', $lonF - $d, $latF - $d, $lonF + $d, $latF + $d);
        $marker = sprintf('%.6F,%.6F', $latF, $lonF);
        $src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . urlencode($bbox) . '&layer=mapnik&marker=' . urlencode($marker);

        $headingDeg  = $heading !== null ? (int) $heading : 0;
        $headingDisp = $heading !== null ? '' : 'display:none';

        return <<<HTML
<div class="map-block" id="map_wrap">
  <iframe id="map_frame" src="{$src}" loading="lazy" title="Fahrzeugposition"></iframe>
  <div id="heading_arrow" class="heading-arrow" style="{$headingDisp};transform:rotate({$headingDeg}deg)">▲</div>
</div>
HTML;
    }

    private function buildDashboardHTML(): string
    {
        $vin = $this->ReadAttributeString('vin');
        if ($vin === '') {
            return $this->buildPlaceholderHTML();
        }

        $model      = $this->readVal($vin, 'Model') ?: $this->ReadAttributeString('model_name');
        $locked     = $this->readVal($vin, 'Locked');
        $mileage    = $this->readVal($vin, 'Mileage');
        $totalRange = $this->readVal($vin, 'TotalRange');
        $evRange    = $this->readVal($vin, 'EVRange');
        $evLevel    = $this->readVal($vin, 'ChargingLevel');
        $chgStatus  = $this->readVal($vin, 'ChargingStatus');
        $chgTime    = $this->readVal($vin, 'ChargingTime');
        $fuelPct    = $this->readVal($vin, 'FuelLevel');
        $batt12v    = $this->readVal($vin, 'Battery12V');
        $lastUpdate = $this->readVal($vin, 'LastUpdate');

        $doorFL = $this->readVal($vin, 'DoorFL');
        $doorFR = $this->readVal($vin, 'DoorFR');
        $doorRL = $this->readVal($vin, 'DoorRL');
        $doorRR = $this->readVal($vin, 'DoorRR');
        $trunk  = $this->readVal($vin, 'Trunk');

        $winFL = $this->readVal($vin, 'WindowFL');
        $winFR = $this->readVal($vin, 'WindowFR');
        $winRL = $this->readVal($vin, 'WindowRL');
        $winRR = $this->readVal($vin, 'WindowRR');

        $lat     = $this->readVal($vin, 'Latitude');
        $lon     = $this->readVal($vin, 'Longitude');
        $heading = $this->readVal($vin, 'Heading');

        [$chgCls, $chgLabel] = $this->classifyChargingStatus($chgStatus);

        $lockCls  = $locked === null ? 'badge-off' : ($locked ? 'badge-on' : 'badge-warn');
        $lockText = $locked === null ? '– Verriegelt' : ($locked ? '🔒 Verriegelt' : '🔓 Entriegelt');

        $modelEsc = htmlspecialchars(($model !== null && $model !== '') ? (string) $model : 'BMW', ENT_QUOTES);

        $doorFLCls = $this->doorClass($doorFL);
        $doorFRCls = $this->doorClass($doorFR);
        $doorRLCls = $this->doorClass($doorRL);
        $doorRRCls = $this->doorClass($doorRR);
        $trunkCls  = $this->doorClass($trunk);

        $winFLCls = $this->windowClass($winFL);
        $winFRCls = $this->windowClass($winFR);
        $winRLCls = $this->windowClass($winRL);
        $winRRCls = $this->windowClass($winRR);

        $mileageStr    = $mileage !== null ? number_format((float) $mileage, 0, ',', '.') . ' km' : '–';
        $totalRangeStr = $totalRange !== null ? "{$totalRange} km" : '–';
        $evRangeStr    = $evRange !== null ? "{$evRange} km" : '–';
        $evLevelStr    = $evLevel !== null ? "{$evLevel}%" : '–';
        $evLevelPct    = $evLevel !== null ? max(0, min(100, (int) $evLevel)) : 0;
        $fuelStr       = $fuelPct !== null ? number_format((float) $fuelPct, 0) . '%' : '–';
        $fuelPctClamp  = $fuelPct !== null ? max(0, min(100, (float) $fuelPct)) : 0;
        // Battery12V never returns data for this vehicle (confirmed
        // 20.08.2026/01.09.2026 -- BMW simply doesn't expose it for every
        // model), so the variable never even gets created -- omit the tile
        // entirely instead of permanently showing a dead "–" placeholder.
        $batt12vTile = $batt12v !== null
            ? '<div class="stat"><span class="stat-label">12V-Batterie</span><span id="stat_batt12v" class="stat-value">' . number_format((float) $batt12v, 0) . '%</span></div>'
            : '';
        // BMW keeps reporting a "time to full" estimate even while idle (not
        // actually charging), which reads as contradictory next to a "Kein
        // Ladevorgang" badge — only show it while charging is actually active.
        $isCharging    = $chgCls === 'badge-green';
        $chgTimeStr    = ($isCharging && $chgTime !== null && (int) $chgTime > 0) ? $this->formatMinutes((int) $chgTime) : '';
        $chgTimeDisp   = $chgTimeStr !== '' ? '' : 'display:none';
        $lastUpdateStr = $lastUpdate !== null ? date('d.m. H:i', strtotime((string) $lastUpdate)) : '–';

        $mapBlock = $this->renderMapBlock($lat, $lon, $heading);

        $initJson = json_encode([
            'Locked'         => $locked,
            'ChargingStatus' => $chgStatus,
            'ChargingTime'   => $chgTime,
            'Latitude'       => $lat,
            'Longitude'      => $lon,
        ]);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:8px}
.header{display:flex;justify-content:space-between;align-items:center;gap:6px;font-size:14px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;white-space:nowrap}
.badge-on{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.badge-green{background:#124a1e;border-color:#2f8a44;color:#7ee89a}
.badge-amber{background:#4a3510;border-color:#8a6a20;color:#f0c060}
.main{display:flex;gap:14px;flex:1;min-height:0}
.car-col{flex:0 0 38%;display:flex;align-items:center;justify-content:center;min-width:0;min-height:0}
.car-col svg{width:100%;height:100%;max-height:100%}
.body-outline{fill:#16233b;stroke:#2a4a7a;stroke-width:2}
.cabin{fill:#0a1526;opacity:.8}
.hl{fill:#ffedc0;opacity:.5}
.tl{fill:#f08060;opacity:.5}
.door{stroke-width:1.5;transition:fill .3s,stroke .3s}
.door-open{fill:#4a2010;stroke:#f08060}
.door-closed{fill:#16233b;stroke:#2a4a7a}
.door-unknown{fill:#0d1b2a;stroke:#2a3a50;stroke-dasharray:3 3}
.charge-port{stroke-width:1.5;transition:fill .3s,stroke .3s}
.charge-port.badge-green{fill:#2f8a44;stroke:#7ee89a}
.charge-port.badge-amber{fill:#8a6a20;stroke:#f0c060}
.charge-port.badge-on{fill:#3a8abf;stroke:#7ec8f0}
.charge-port.badge-off{fill:#1a2535;stroke:#2a3a50}
.stats-col{flex:1;display:flex;flex-direction:column;gap:10px;min-width:0;justify-content:center}
.bar-tile{display:flex;flex-direction:column;gap:3px}
.bar-tile-head{display:flex;justify-content:space-between;font-size:12px;color:#8aa8c8}
.bar-track{height:10px;background:#1a2535;border-radius:5px;overflow:hidden}
.bar-fill{height:100%;border-radius:5px;transition:width .4s}
.bar-fill.ev{background:linear-gradient(90deg,#2f8a44,#7ee89a)}
.bar-fill.fuel{background:linear-gradient(90deg,#8a6a20,#f0c060)}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;margin-top:4px}
.stat{display:flex;flex-direction:column;gap:1px}
.stat-label{font-size:10px;color:#4a6a8a;text-transform:uppercase;letter-spacing:.03em}
.stat-value{font-size:15px;font-weight:700;color:#d0e8ff}
.footer-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex:none;font-size:11px;border-top:1px solid #1e3a5f;padding-top:6px}
.chg-time{color:#8aa8c8}
.last-update{margin-left:auto;font-size:10px;color:#3a5a7a}
.map-block{position:relative;height:110px;border-radius:8px;overflow:hidden;flex:none;background:#131f33}
.map-block iframe{width:100%;height:100%;border:0}
.heading-arrow{position:absolute;bottom:6px;right:6px;width:22px;height:22px;border-radius:50%;background:rgba(13,27,42,.8);border:1px solid #2a4a7a;color:#7ec8f0;display:flex;align-items:center;justify-content:center;font-size:13px;transform-origin:50% 50%;pointer-events:none}
</style>
</head>
<body>
<div class="header">
  <span class="model">🚗 {$modelEsc}</span>
  <span id="lock_badge" class="badge {$lockCls}">{$lockText}</span>
</div>
<div class="main">
  <div class="car-col">
    <svg viewBox="0 0 220 460" preserveAspectRatio="xMidYMid meet">
      <rect class="body-outline" x="25" y="10" width="170" height="440" rx="55"/>
      <ellipse class="hl" cx="55" cy="28" rx="10" ry="6"/>
      <ellipse class="hl" cx="165" cy="28" rx="10" ry="6"/>
      <rect class="cabin" x="60" y="100" width="100" height="180" rx="18"/>
      <rect id="door_fl" class="door {$doorFLCls}" x="25" y="100" width="35" height="85" rx="8"><title>Tuer vorne links</title></rect>
      <rect id="door_fr" class="door {$doorFRCls}" x="160" y="100" width="35" height="85" rx="8"><title>Tuer vorne rechts</title></rect>
      <rect id="door_rl" class="door {$doorRLCls}" x="25" y="195" width="35" height="85" rx="8"><title>Tuer hinten links</title></rect>
      <rect id="door_rr" class="door {$doorRRCls}" x="160" y="195" width="35" height="85" rx="8"><title>Tuer hinten rechts</title></rect>
      <rect id="door_trunk" class="door {$trunkCls}" x="45" y="390" width="130" height="45" rx="10"><title>Kofferraum</title></rect>
      <rect id="window_fl" class="door {$winFLCls}" x="25" y="100" width="35" height="16" rx="4"><title>Fenster vorne links</title></rect>
      <rect id="window_fr" class="door {$winFRCls}" x="160" y="100" width="35" height="16" rx="4"><title>Fenster vorne rechts</title></rect>
      <rect id="window_rl" class="door {$winRLCls}" x="25" y="195" width="35" height="16" rx="4"><title>Fenster hinten links</title></rect>
      <rect id="window_rr" class="door {$winRRCls}" x="160" y="195" width="35" height="16" rx="4"><title>Fenster hinten rechts</title></rect>
      <rect class="tl" x="35" y="435" width="18" height="8" rx="2"/>
      <rect class="tl" x="167" y="435" width="18" height="8" rx="2"/>
      <circle id="charge_port" class="charge-port {$chgCls}" cx="21" cy="255" r="9"><title>Ladeanschluss (Position ungefaehr)</title></circle>
    </svg>
  </div>
  <div class="stats-col">
    <div class="bar-tile">
      <div class="bar-tile-head"><span>🔋 Ladestand</span><span id="ev_bar_text">{$evLevelStr}</span></div>
      <div class="bar-track"><div id="ev_bar_fill" class="bar-fill ev" style="width:{$evLevelPct}%"></div></div>
    </div>
    <div class="bar-tile">
      <div class="bar-tile-head"><span>⛽ Kraftstoff</span><span id="fuel_bar_text">{$fuelStr}</span></div>
      <div class="bar-track"><div id="fuel_bar_fill" class="bar-fill fuel" style="width:{$fuelPctClamp}%"></div></div>
    </div>
    <div class="stat-grid">
      <div class="stat"><span class="stat-label">Gesamtreichweite</span><span id="stat_range" class="stat-value">{$totalRangeStr}</span></div>
      <div class="stat"><span class="stat-label">EV-Reichweite</span><span id="stat_evrange" class="stat-value">{$evRangeStr}</span></div>
      <div class="stat"><span class="stat-label">Kilometerstand</span><span id="stat_mileage" class="stat-value">{$mileageStr}</span></div>
      {$batt12vTile}
    </div>
  </div>
</div>
{$mapBlock}
<div class="footer-row">
  <span id="charge_badge" class="badge {$chgCls}">⚡ {$chgLabel}</span>
  <span id="chg_time_wrap" class="chg-time" style="{$chgTimeDisp}"><span id="stat_chgtime">{$chgTimeStr}</span> bis voll</span>
  <span id="last_update" class="last-update">{$lastUpdateStr}</span>
</div>
<script>
// WebFront injects its own body{margin-top:...;margin-bottom:...} (reserved
// space for the tile's title/expand-icon overlay). Measure it and size body
// to exactly fill what's left instead of guessing a fixed pixel value.
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};

function setDoor(id, open) {
  var el = document.getElementById('door_' + id);
  if (!el) return;
  el.setAttribute('class', 'door ' + (open === true ? 'door-open' : (open === false ? 'door-closed' : 'door-unknown')));
}

function classifyCharging(status) {
  if (!status) return ['badge-off', 'Unbekannt'];
  var s = status.toUpperCase();
  // Same order as classifyChargingStatus() in PHP -- terminal states before
  // the generic "CHARGING" match, since e.g. "CHARGINGENDED" contains both.
  if (s.indexOf('ENDED') >= 0 || s.indexOf('FINISHED') >= 0 || s.indexOf('TARGET') >= 0 || s.indexOf('COMPLETE') >= 0) return ['badge-on', 'Vollstaendig geladen'];
  if (s.indexOf('NOCHARGING') >= 0 || s.indexOf('NOT_CHARGING') >= 0 || s.indexOf('INVALID') >= 0) return ['badge-off', 'Kein Ladevorgang'];
  if (s.indexOf('CHARGING') >= 0) return ['badge-green', 'Laedt'];
  if (s.indexOf('WAIT') >= 0 || s.indexOf('PLUG') >= 0) return ['badge-amber', 'Angeschlossen'];
  return ['badge-off', status];
}

function setWindow(id, status) {
  var el = document.getElementById('window_' + id);
  if (!el) return;
  var cls = (status == null || status === '') ? 'door-unknown' : (String(status).toUpperCase() === 'CLOSED' ? 'door-closed' : 'door-open');
  el.setAttribute('class', 'door ' + cls);
}

// The iframe is a different origin (openstreetmap.org), so it can only be
// repositioned by reloading its src with a new bbox/marker -- there is no
// way to pan it from here. Fine at a 30min poll interval.
function updateMap(lat, lon) {
  var frame = document.getElementById('map_frame');
  if (!frame || lat == null || lon == null) return;
  var d = 0.004;
  var bbox = (lon - d) + ',' + (lat - d) + ',' + (lon + d) + ',' + (lat + d);
  frame.src = 'https://www.openstreetmap.org/export/embed.html?bbox=' + encodeURIComponent(bbox) + '&layer=mapnik&marker=' + encodeURIComponent(lat + ',' + lon);
}

function updateHeadingArrow(deg) {
  var el = document.getElementById('heading_arrow');
  if (!el) return;
  if (deg == null) { el.style.display = 'none'; return; }
  el.style.display = '';
  el.style.transform = 'rotate(' + deg + 'deg)';
}

function setCharging(status) {
  var cls = classifyCharging(status);
  var badge = document.getElementById('charge_badge');
  if (badge) { badge.className = 'badge ' + cls[0]; badge.textContent = '⚡ ' + cls[1]; }
  var port = document.getElementById('charge_port');
  if (port) { port.setAttribute('class', 'charge-port ' + cls[0]); }
}

function setLocked(v) {
  var el = document.getElementById('lock_badge');
  if (!el) return;
  if (v === true) { el.className = 'badge badge-on'; el.textContent = '🔒 Verriegelt'; }
  else if (v === false) { el.className = 'badge badge-warn'; el.textContent = '🔓 Entriegelt'; }
  else { el.className = 'badge badge-off'; el.textContent = '– Verriegelt'; }
}

function setBar(fillId, textId, v, suffix) {
  var fill = document.getElementById(fillId);
  var text = document.getElementById(textId);
  var pct = (v == null) ? 0 : Math.max(0, Math.min(100, v));
  if (fill) fill.style.width = pct + '%';
  if (text) text.textContent = (v == null ? '–' : v + (suffix || ''));
}

function setText(id, text) {
  var el = document.getElementById(id);
  if (el) el.textContent = text;
}

// BMW keeps reporting a "time to full" estimate even while idle — only show
// the chip while charging is actually active (mirrors the PHP initial render).
function updateChargeTimeVisibility() {
  var wrap = document.getElementById('chg_time_wrap');
  var el   = document.getElementById('stat_chgtime');
  if (!wrap || !el) return;
  var isCharging = classifyCharging(state.ChargingStatus)[0] === 'badge-green';
  var v = state.ChargingTime;
  if (!isCharging || v == null || v <= 0) { wrap.style.display = 'none'; return; }
  wrap.style.display = '';
  var h = Math.floor(v / 60), m = v % 60;
  el.textContent = (h > 0 ? h + 'h ' : '') + m + 'min';
}

window.handleMessage = function(raw) {
  var data = JSON.parse(raw);
  var key = data.key, val = data.value;

  if (key === 'Locked') { setLocked(val); state.Locked = val; }
  else if (key === 'ChargingStatus') { setCharging(val); state.ChargingStatus = val; updateChargeTimeVisibility(); }
  else if (key === 'ChargingLevel') { setBar('ev_bar_fill', 'ev_bar_text', val, '%'); }
  else if (key === 'FuelLevel') { setBar('fuel_bar_fill', 'fuel_bar_text', (val == null ? null : Math.round(val)), '%'); }
  else if (key === 'ChargingTime') { state.ChargingTime = val; updateChargeTimeVisibility(); }
  else if (key === 'TotalRange') { setText('stat_range', val == null ? '–' : val + ' km'); }
  else if (key === 'EVRange') { setText('stat_evrange', val == null ? '–' : val + ' km'); }
  else if (key === 'Mileage') { setText('stat_mileage', val == null ? '–' : val + ' km'); }
  else if (key === 'Battery12V') { setText('stat_batt12v', val == null ? '–' : Math.round(val) + '%'); }
  else if (key === 'LastUpdate') { setText('last_update', val); }
  else if (key === 'DoorFL') { setDoor('fl', val); }
  else if (key === 'DoorFR') { setDoor('fr', val); }
  else if (key === 'DoorRL') { setDoor('rl', val); }
  else if (key === 'DoorRR') { setDoor('rr', val); }
  else if (key === 'Trunk')  { setDoor('trunk', val); }
  else if (key === 'WindowFL') { setWindow('fl', val); }
  else if (key === 'WindowFR') { setWindow('fr', val); }
  else if (key === 'WindowRL') { setWindow('rl', val); }
  else if (key === 'WindowRR') { setWindow('rr', val); }
  else if (key === 'Latitude')  { if (val !== state.Latitude)  { state.Latitude = val;  updateMap(state.Latitude, state.Longitude); } }
  else if (key === 'Longitude') { if (val !== state.Longitude) { state.Longitude = val; updateMap(state.Latitude, state.Longitude); } }
  else if (key === 'Heading')  { updateHeadingArrow(val); }
};
</script>
</body>
</html>
HTML;
    }

    private function buildPlaceholderHTML(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow:hidden;display:flex;align-items:center;justify-content:center;background:#0d1b2a;color:#8aa8c8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;text-align:center;padding:20px}
</style>
</head>
<body>
<div>🚗 Noch nicht angemeldet.<br>Bitte in den Instanz-Einstellungen anmelden.</div>
</body>
</html>
HTML;
    }
}
