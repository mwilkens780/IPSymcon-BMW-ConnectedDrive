# BMW CarData – IP-Symcon Modul

PHP-Modul zur Integration von BMW-Fahrzeugdaten in [IP-Symcon](https://www.symcon.de/) über die offizielle **BMW CarData API**.

---

## Voraussetzungen

- IP-Symcon 6.0+ (getestet auf 9.0)
- BMW-Fahrzeug mit aktivem **Connected Package Professional** (oder gleichwertig)
- BMW-Account (My BMW)
- BMW CarData **Client-ID** (kostenlose Registrierung, siehe unten)

---

## Installation

### Über die IPS-Modulverwaltung

1. IPS-Konsole → **Kerninstanzen → Modulverwaltung → +**
2. URL eintragen: `https://github.com/mwilkens780/IPSymcon-BMW-ConnectedDrive`
3. Installieren

### Instanz anlegen

1. Objektbaum → rechte Maustaste → **Objekt hinzufügen → Instanz**
2. Suche nach: `BMW CarData`
3. Instanz erstellen

---

## Client-ID beschaffen

Die Client-ID ist kostenlos und wird einmalig im **BMW CarData Kundenportal** erstellt:

1. Öffne: **https://bmw-cardata.bmwgroup.com/customer/public**
2. Mit BMW-Account einloggen
3. Neue Client-Applikation anlegen
4. Die generierte UUID ist deine `client_id`

**Fahrzeugzuweisung / Datenschutz:**
Dein Fahrzeug muss im BMW CarData-Portal für Datenzugriff freigegeben sein. Die Einstellung findest du in deinem BMW-Account unter:
- MyBMW → Fahrzeug → CarData / Datenschutzeinstellungen
- Direktlink-Muster: `https://www.bmw.de/de-de/mybmw/mapped-vehicle/{HASH}/cardata`

---

## Konfiguration

| Feld | Beschreibung |
|------|-------------|
| `client_id` | UUID aus dem BMW CarData Portal |
| `update_interval` | Aktualisierungsintervall in Sekunden (min. 60, Standard: 300) |

---

## Erstanmeldung (einmalig)

Das Modul verwendet **OAuth2 Device Code Flow mit PKCE** – kein Passwort wird in IPS gespeichert.

**Ablauf:**
1. Client-ID eintragen und speichern
2. Button **„1. Anmeldung starten"** klicken
3. Instanz-Konfiguration **schließen und wieder öffnen**
4. Der generierte **Code** und die **URL** erscheinen jetzt oben in der Konfigurationsansicht
5. URL im Browser öffnen, Code eingeben, mit BMW-Account bestätigen (ca. 5 Minuten Zeit)
6. Button **„2. Anmeldung prüfen"** klicken → fertig

**Token-Laufzeiten:**

| Token | Gültigkeitsdauer | Erneuerung |
|-------|-----------------|------------|
| Access-Token | ~1 Stunde | Automatisch bei jedem Datenabruf |
| Refresh-Token | ~90 Tage | Automatisch beim Erneuern des Access-Tokens |
| Nach Ablauf Refresh | – | Einmalig Device Code Flow wiederholen |

---

## IPS-Variablen

Pro Fahrzeug (VIN als Prefix) werden folgende Variablen angelegt, sofern das Fahrzeug die Daten liefert:

| Variable | Typ | Beschreibung |
|----------|-----|-------------|
| `{VIN}_Model` | String | Fahrzeugmodell |
| `{VIN}_Mileage` | Integer | Kilometerstand (km) |
| `{VIN}_FuelLevel` | Float | Kraftstoffstand (%) |
| `{VIN}_RemainingFuel` | Float | Verbleibender Kraftstoff (L) |
| `{VIN}_TotalRange` | Integer | Gesamtreichweite (km) |
| `{VIN}_EVRange` | Integer | Elektrische Reichweite (km) |
| `{VIN}_ChargingLevel` | Integer | Ladestand HV-Batterie (%) |
| `{VIN}_ChargingStatus` | String | Ladestatus (CHARGING, COMPLETE, etc.) |
| `{VIN}_ChargingTime` | Integer | Zeit bis Vollladung (Minuten) |
| `{VIN}_IsPlugged` | Boolean | Ladekabel eingesteckt |
| `{VIN}_Latitude` | Float | GPS Breitengrad |
| `{VIN}_Longitude` | Float | GPS Längengrad |
| `{VIN}_Locked` | Boolean | Fahrzeug verriegelt |
| `{VIN}_Battery12V` | Float | 12V Batterie Ladezustand (%) |
| `{VIN}_LastUpdate` | String | Zeitstempel letzter Abruf (ISO 8601) |

Variablen für nicht vorhandene Funktionen (z. B. EV-Daten bei Verbrenner) werden einfach nicht angelegt.

---

## IPS-Skript-Beispiele

```php
$id = 12345; // Instanz-ID

BMWCD_FetchVehicleData($id);       // Daten manuell aktualisieren
BMWCD_StartDeviceAuth($id);        // Device Code Flow starten
BMWCD_CheckDeviceAuth($id);        // Auth-Status prüfen (nach Browser-Login)
BMWCD_ResetAuth($id);              // Token löschen, neuen Login erzwingen
```

---

## Hinweise zur API

- **Keine Remote Services**: Die BMW CarData API ist rein lesend (Telemetrie, Ladeverlauf, Reifendiagnose). Türen sperren/öffnen, Klimaanlage starten etc. sind über diese API nicht möglich.
- **Rate Limiting**: BMW limitiert die Anfragen. Das Modul hält standardmäßig 300 Sekunden Abstand (konfigurierbar, Minimum 60s).
- **Telematik-Container**: Das Modul legt automatisch einen Container (`IPSymcon_BMWCD`) in der BMW CarData API an, der die gewünschten Telematik-Keys abonniert. Dieser wird wiederverwendet.

---

## Entwicklungshistorie & bekannte Fallstricke

Diese Sektion dokumentiert Erkenntnisse aus der Entwicklung für zukünftige Maintainer.

### IPS-spezifisch

| Problem | Ursache | Lösung |
|---------|---------|--------|
| „Ungültiger Build" in Modulverwaltung | IPS 9.0 erzwingt Feld `"build": <int>` in `library.json` | `"build": 2` hinzugefügt |
| Klasse nicht gefunden beim Anlegen der Instanz | IPS leitet Klassenname aus Verzeichnisname ab (Unterstriche entfernt): `BMW_ConnectedDrive` → `BMWConnectedDrive` | Klasse in `module.php` entsprechend benennen |
| Parse-Fehler beim Laden des Moduls | Unicode-Zeichen (Emojis, typografische Anführungszeichen `„"`, `─`) in PHP-String-Literalen werden vom IPS-PHP-Tokenizer nicht korrekt verarbeitet | Nur ASCII in PHP-Strings verwenden |
| Keine Konfigurationsseite | IPS 6+ benötigt `form.json` für die Konfigurationsansicht – wird nicht automatisch generiert | `form.json` mit allen Feldern erstellt |
| Dynamische Inhalte in Konfigurationsformular | Felder in `form.json` sind statisch | `GetConfigurationForm()` in der Modulklasse überschreiben und JSON dynamisch zusammenbauen |

### BMW API

| Problem | Ursache | Lösung |
|---------|---------|--------|
| HTTP 401 auf `cocoapi.bmwgroup.com` | BMW hat die inoffizielle API (bimmer_connected) am 29.09.2025 abgeschaltet | Auf offizielle CarData API (`api-cardata.bmwgroup.com`) umgestellt |
| Container-ID fehlt in API-Antwort | API gibt Feld `containerId` zurück, nicht `id` | `$response['containerId']` statt `$response['id']` |
| BMW CarData Portal-Seite nicht erreichbar | `bmw-cardata.bmwgroup.com` (ohne Pfad) gibt 403 | Direktlink: `.../customer/public` oder `.../thirdparty/public/home` |
| `hcaptcha.com/demo` nicht mehr verfügbar | Seite eingestellt | Irrelevant – altes Authentifizierungsverfahren wurde durch Device Code Flow ersetzt |

### Archivierte Referenz

Die ursprüngliche Implementierung basierte auf der Python-Bibliothek [bimmer_connected](https://github.com/bimmerconnected/bimmer_connected), die im März 2026 archiviert wurde, nachdem BMW die inoffizielle API im September 2025 gesperrt hatte. Das Modul wurde daraufhin vollständig auf die offizielle BMW CarData API umgestellt.

---

## Lizenz

MIT – Martin Wilkens, 2026
