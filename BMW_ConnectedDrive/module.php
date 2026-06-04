<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';

// ─── IPS module status codes ──────────────────────────────────────────────────
// IS_ACTIVE    = 102
// IS_INACTIVE  = 104
// IS_NOTCREATED = 201

class BMW_ConnectedDrive extends IPSModule
{
    // ─── Module lifecycle ─────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('username',        '');
        $this->RegisterPropertyString('password',        '');
        $this->RegisterPropertyString('region',          'ROW');
        $this->RegisterPropertyString('hcaptcha_token',  '');
        $this->RegisterPropertyInteger('update_interval', 300);

        // OAuth store: access_token, refresh_token, gcid, expires_at
        $this->RegisterAttributeString('oauth_store', '');

        $this->RegisterTimer('UpdateTimer', 0, 'BMWCD_FetchVehicleData($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (trim($this->ReadPropertyString('username')) === ''
            || trim($this->ReadPropertyString('password')) === '') {
            $this->SetStatus(201); // not configured
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102); // active
    }

    // ─── Public methods (callable as BMW_xxx($id) from IPS scripts) ───────────

    public function FetchVehicleData(): bool
    {
        try {
            $api      = $this->createApi();
            $vehicles = $api->fetchVehicleList();

            foreach ($vehicles as $vehicle) {
                $this->updateVehicleVariables($vehicle);
            }

            $this->SetStatus(102);
            return true;
        } catch (\Exception $e) {
            $this->LogMessage('BMW FetchVehicleData: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
            return false;
        }
    }

    public function LockDoors(string $vin): bool
    {
        return $this->executeRemote($vin, 'door-lock');
    }

    public function UnlockDoors(string $vin): bool
    {
        return $this->executeRemote($vin, 'door-unlock');
    }

    public function StartClimate(string $vin): bool
    {
        return $this->executeRemote($vin, 'climate-now');
    }

    public function FlashLights(string $vin): bool
    {
        return $this->executeRemote($vin, 'lights-flash');
    }

    public function HonkHorn(string $vin): bool
    {
        return $this->executeRemote($vin, 'horn-blow');
    }

    /** Clears the OAuth store, forcing a full re-login on next access. */
    public function ResetAuth(): void
    {
        $this->WriteAttributeString('oauth_store', '');
        $this->LogMessage('BMW ConnectedDrive: OAuth-Store zurückgesetzt. Neuen hCaptcha-Token konfigurieren.', KL_MESSAGE);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function createApi(): BMWApi
    {
        $store = $this->ensureValidStore();
        return new BMWApi($this->getServer(), $store['access_token']);
    }

    private function ensureValidStore(): array
    {
        $auth  = new BMWAuth(
            $this->ReadPropertyString('username'),
            $this->ReadPropertyString('password'),
            $this->ReadPropertyString('region')
        );

        $store = $this->readStore();

        if (empty($store)) {
            // First login – hCaptcha token mandatory
            $hcaptcha = trim($this->ReadPropertyString('hcaptcha_token'));
            if ($hcaptcha === '') {
                throw new \RuntimeException(
                    'OAuth-Store leer und kein hCaptcha-Token konfiguriert. '
                    . 'Einmaligen Token in der Modulkonfiguration unter "hcaptcha_token" eintragen.'
                );
            }
            $store = $auth->login($hcaptcha);
            $this->saveStore($store);
            $this->LogMessage(
                'BMW ConnectedDrive: Erster Login erfolgreich (GCID: ' . ($store['gcid'] ?? '–') . '). '
                . 'Bitte den hCaptcha-Token in der Konfiguration leeren.',
                KL_MESSAGE
            );
        } else {
            try {
                $store = $auth->loginWithStore($store);
                $this->saveStore($store);
            } catch (\RuntimeException $e) {
                $this->LogMessage('BMW ConnectedDrive: ' . $e->getMessage(), KL_ERROR);
                throw $e;
            }
        }

        return $store;
    }

    private function getServer(): string
    {
        return strtoupper($this->ReadPropertyString('region')) === 'NA'
            ? BMW_SERVER_NA
            : BMW_SERVER_ROW;
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

    private function executeRemote(string $vin, string $service): bool
    {
        try {
            $api = $this->createApi();
            $api->executeRemoteService($vin, $service);
            return true;
        } catch (\Exception $e) {
            $this->LogMessage("BMW Remote '$service' ($vin): " . $e->getMessage(), KL_ERROR);
            return false;
        }
    }

    /**
     * Creates or updates IPS variables for a single vehicle.
     * Variable idents use the VIN as prefix so multiple vehicles coexist.
     */
    private function updateVehicleVariables(array $vehicle): void
    {
        $vin = trim((string) ($vehicle['vin'] ?? ''));
        if ($vin === '') {
            return;
        }

        $state = $vehicle['state'] ?? [];

        // Mileage (km)
        $mileage = $state['currentMileage']['mileage'] ?? null;
        if ($mileage !== null) {
            $this->setVar($vin . '_Mileage', 'Kilometerstand', VARIABLETYPE_INTEGER, (int) $mileage);
        }

        // Battery / EV range
        $ev = $state['electricChargingState'] ?? [];
        if (isset($ev['chargingLevelPercent'])) {
            $this->setVar($vin . '_BatteryLevel', 'Akkustand (%)', VARIABLETYPE_INTEGER, (int) $ev['chargingLevelPercent']);
        }
        if (isset($ev['remainingRangeMeter'])) {
            $this->setVar($vin . '_RemainingRangeEV', 'Reichweite EV (km)', VARIABLETYPE_INTEGER, (int) round((float) $ev['remainingRangeMeter'] / 1000));
        }

        // Fuel level (L)
        $fuel = $state['combustionFuelLevel']['remainingFuelLiters'] ?? null;
        if ($fuel !== null) {
            $this->setVar($vin . '_FuelLevel', 'Kraftstoff (L)', VARIABLETYPE_FLOAT, (float) $fuel);
        }

        // Location
        $coords = $state['location']['coordinates'] ?? [];
        if (isset($coords['latitude'])) {
            $this->setVar($vin . '_Latitude', 'Breitengrad', VARIABLETYPE_FLOAT, (float) $coords['latitude']);
        }
        if (isset($coords['longitude'])) {
            $this->setVar($vin . '_Longitude', 'Längengrad', VARIABLETYPE_FLOAT, (float) $coords['longitude']);
        }

        // Lock state
        $lockState = $state['doorsState']['combinedSecurityState'] ?? null;
        if ($lockState !== null) {
            $this->setVar($vin . '_Locked', 'Verriegelt', VARIABLETYPE_BOOLEAN, $lockState === 'LOCKED');
        }

        // Last update timestamp (ISO 8601)
        $this->setVar($vin . '_LastUpdate', 'Letztes Update', VARIABLETYPE_STRING, date('c'));
    }

    /**
     * Creates an IPS variable under this instance if it doesn't exist yet,
     * then sets its value.
     */
    private function setVar(string $ident, string $name, int $type, $value): void
    {
        $exists = @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;

        if (!$exists) {
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
}
