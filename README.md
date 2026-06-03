# BMW ConnectedDrive – IP-Symcon Modul

PHP-Modul zur Integration von BMW ConnectedDrive in [IP-Symcon](https://www.symcon.de/).

## Features

- **OAuth2 + PKCE** vollständig in PHP implementiert (kein externer Dienst)
- **hCaptcha** einmalig für den ersten Login
- Token-**Refresh** automatisch, wenn der Access-Token abläuft
- **Fahrzeugdaten** als IPS-Variablen (Km-Stand, Akkustand, Reichweite, Tankinhalt, Position, Verriegelung)
- **Remote Services**: Türen verriegeln/entriegeln, Klimaanlage starten, Lichtsignal, Hupe
- Unterstützt Region **ROW** (Europa) und **NA** (Nordamerika)

---

## Installation in IP-Symcon

### Über die Modulverwaltung (empfohlen)

1. IPS-Konsole öffnen → **Kerninstanzen → Modulverwaltung**
2. **+** → URL eintragen: `https://github.com/mwilkens780/IPSymcon-BMW-ConnectedDrive`
3. Installieren → Modul erscheint unter `BMW ConnectedDrive`

### Manuell (Entwicklung)

Modul-Ordner `BMW_ConnectedDrive/` in das IPS-Module-Verzeichnis legen:

```
<IPS-Pfad>/modules/mwilkens780/IPSymcon-BMW-ConnectedDrive/
```

---

## Konfiguration

| Feld              | Beschreibung                                                |
|-------------------|-------------------------------------------------------------|
| `username`        | BMW ConnectedDrive E-Mail-Adresse                           |
| `password`        | BMW ConnectedDrive Passwort                                 |
| `region`          | `ROW` (Europa) oder `NA` (Nordamerika)                     |
| `hcaptcha_token`  | **Einmalig** – Token für den ersten Login (danach leeren!) |
| `update_interval` | Aktualisierungsintervall in Sekunden (Standard: 300)        |

### Erster Login – hCaptcha-Token

BMW schützt den ersten Login mit hCaptcha.  
Token generieren unter: **https://hcaptcha.com/demo**  
(SiteKey: `bcb2ddbb-d4e8-4e2c-b324-c9e2e4e1f4b3`)

1. Token in das Konfigurationsfeld `hcaptcha_token` eintragen
2. Konfiguration speichern (ApplyChanges)
3. Skript ausführen: `BMW_FetchVehicleData($id);`
4. **Token im Konfigurationsfeld leeren** (einmalige Verwendung!)

Ab dem zweiten Start wird der gespeicherte Refresh-Token automatisch verwendet.

---

## IPS-Variablen

Pro Fahrzeug (VIN als Prefix) werden folgende Variablen angelegt:

| Variable                 | Typ   | Einheit |
|--------------------------|-------|---------|
| `{VIN}_Mileage`          | Int   | km      |
| `{VIN}_BatteryLevel`     | Int   | %       |
| `{VIN}_RemainingRangeEV` | Int   | km      |
| `{VIN}_FuelLevel`        | Float | L       |
| `{VIN}_Locked`           | Bool  |         |
| `{VIN}_Latitude`         | Float |         |
| `{VIN}_Longitude`        | Float |         |
| `{VIN}_LastUpdate`       | String| ISO 8601|

---

## IPS-Skript-Beispiele

```php
$id = 12345; // Instanz-ID des Moduls

// Fahrzeugdaten manuell aktualisieren
BMW_FetchVehicleData($id);

// Remote Services
$vin = 'WBAXXX123456789';
BMW_LockDoors($id, $vin);
BMW_UnlockDoors($id, $vin);
BMW_StartClimate($id, $vin);
BMW_FlashLights($id, $vin);
BMW_HonkHorn($id, $vin);

// OAuth-Store zurücksetzen (erzwingt neuen hCaptcha-Login)
BMW_ResetAuth($id);
```

---

## Standalone-Test (ohne IPS)

```bash
cd BMW_ConnectedDrive
# Credentials in test_auth.php eintragen
php test_auth.php
```

---

## Hinweise

- Die BMW-API kann sich jederzeit ändern (Bibliothek auf Basis der archivierten Python-Referenz [bimmer_connected](https://github.com/bimmerconnected/bimmer_connected)).
- Alle API-Endpunkte und Header sind als Konstanten ausgelagert und leicht anpassbar.
- `auth.php` und `api.php` haben **keine IPS-Abhängigkeiten** und sind separat testbar.

---

## Lizenz

MIT – Martin Wilkens, 2026
