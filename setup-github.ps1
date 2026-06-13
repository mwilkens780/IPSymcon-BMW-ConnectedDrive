# setup-github.ps1 – einmalig ausführen um GitHub-Repo anzulegen
# Aufruf: powershell -ExecutionPolicy Bypass -File setup-github.ps1

Set-Location $PSScriptRoot

Write-Host "=== BMW ConnectedDrive – GitHub Setup ===" -ForegroundColor Cyan
Write-Host ""

# 1. gh CLI authentifizieren (öffnet Browser)
Write-Host "Schritt 1: GitHub-Login (Browser öffnet sich)..."
gh auth login --git-protocol https --web
if ($LASTEXITCODE -ne 0) { Write-Error "Login fehlgeschlagen."; exit 1 }

Write-Host ""
Write-Host "Schritt 2: Repo anlegen..."
gh repo create mwilkens780/IPSymcon-BMW-ConnectedDrive `
    --public `
    --description "BMW ConnectedDrive integration for IP-Symcon (OAuth2+PKCE, vehicle data, remote services)" `
    --source . `
    --remote origin `
    --push

if ($LASTEXITCODE -ne 0) { Write-Error "Repo-Erstellung fehlgeschlagen."; exit 1 }

Write-Host ""
Write-Host "Fertig! Repo: https://github.com/mwilkens780/IPSymcon-BMW-ConnectedDrive" -ForegroundColor Green
