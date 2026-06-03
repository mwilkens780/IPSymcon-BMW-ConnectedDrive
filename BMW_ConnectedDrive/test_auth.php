<?php

/**
 * Standalone test script – runs WITHOUT IP-Symcon.
 *
 * Usage:
 *   1. Fill in your BMW credentials and a fresh hCaptcha token below.
 *   2. php test_auth.php
 *
 * Obtain a hCaptcha token once at:
 *   https://hcaptcha.com/demo  (sitekey: bcb2ddbb-d4e8-4e2c-b324-c9e2e4e1f4b3)
 * The token is single-use; generate a new one each time you run a fresh login.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';

// ─── Configuration ────────────────────────────────────────────────────────────

$username      = 'your-email@example.com';
$password      = 'your-password';
$region        = 'ROW';   // ROW (Europe) or NA (North America)
$hcaptchaToken = '';       // Paste fresh token here for first login

// ─── Test: first login ────────────────────────────────────────────────────────

echo "=== BMW ConnectedDrive – Auth-Test ===" . PHP_EOL . PHP_EOL;

$auth = new BMWAuth($username, $password, $region);

if ($hcaptchaToken !== '') {
    echo "1) Vollständiger Login (OAuth2+PKCE + hCaptcha) ..." . PHP_EOL;
    try {
        $store = $auth->login($hcaptchaToken);
        echo "   OK – access_token: " . substr($store['access_token'], 0, 20) . "..." . PHP_EOL;
        echo "   gcid:       " . ($store['gcid'] ?: '(leer)') . PHP_EOL;
        echo "   expires_at: " . date('Y-m-d H:i:s', $store['expires_at']) . PHP_EOL;

        // Persist for next run (plain text – test only!)
        file_put_contents(__DIR__ . '/test_store.json', json_encode($store, JSON_PRETTY_PRINT));
        echo "   Store gespeichert in test_store.json" . PHP_EOL;
    } catch (\Exception $e) {
        echo "   FEHLER: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
} else {
    echo "1) Kein hCaptcha-Token angegeben – überspringe Erst-Login." . PHP_EOL;
}

echo PHP_EOL;

// ─── Test: refresh / re-use existing store ────────────────────────────────────

$storePath = __DIR__ . '/test_store.json';

if (file_exists($storePath)) {
    echo "2) loginWithStore() aus test_store.json ..." . PHP_EOL;
    $existingStore = json_decode(file_get_contents($storePath), true);

    try {
        $refreshed = $auth->loginWithStore($existingStore);
        echo "   OK – Token " . (
            $refreshed['expires_at'] === $existingStore['expires_at'] ? 'noch gültig (unverändert)' : 'refreshed'
        ) . PHP_EOL;
        echo "   expires_at: " . date('Y-m-d H:i:s', $refreshed['expires_at']) . PHP_EOL;
        file_put_contents($storePath, json_encode($refreshed, JSON_PRETTY_PRINT));
    } catch (\Exception $e) {
        echo "   FEHLER: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
} else {
    echo "2) test_store.json nicht gefunden – Schritt 1 zuerst ausführen." . PHP_EOL;
}

echo PHP_EOL;

// ─── Test: vehicle list ───────────────────────────────────────────────────────

if (file_exists($storePath)) {
    echo "3) Fahrzeugliste abrufen ..." . PHP_EOL;
    $store  = json_decode(file_get_contents($storePath), true);
    $server = strtoupper($region) === 'NA' ? BMW_SERVER_NA : BMW_SERVER_ROW;
    $api    = new BMWApi($server, $store['access_token']);

    try {
        $vehicles = $api->fetchVehicleList();
        echo "   " . count($vehicles) . " Fahrzeug(e) gefunden." . PHP_EOL;
        foreach ($vehicles as $v) {
            $vin   = $v['vin']   ?? '(kein VIN)';
            $model = $v['model'] ?? '(kein Modell)';
            $km    = $v['state']['currentMileage']['mileage'] ?? '?';
            echo "   - $vin  $model  ($km km)" . PHP_EOL;
        }
    } catch (\Exception $e) {
        echo "   FEHLER: " . $e->getMessage() . PHP_EOL;
    }
}

echo PHP_EOL . "=== Test abgeschlossen ===" . PHP_EOL;
