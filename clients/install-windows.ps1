[CmdletBinding()]
param(
    [string]$Url,
    [string]$ClientId,
    [string]$Extension,
    [switch]$DryRun
)
$ErrorActionPreference = 'Stop'

$pythonArgs = @()
$pythonCommand = Get-Command python.exe -ErrorAction SilentlyContinue
if (-not $pythonCommand) {
    $pythonCommand = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($pythonCommand) { $pythonArgs = @('-3') }
}
if (-not $pythonCommand) {
    throw 'Python 3 wurde nicht gefunden. Bitte Python 3 installieren (inklusive PATH bzw. py-Launcher) und erneut ausführen.'
}
& $pythonCommand.Source @pythonArgs -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)'
if ($LASTEXITCODE -ne 0) { throw 'Python 3.9 oder neuer ist erforderlich.' }

if (-not $Url) { $Url = Read-Host 'Middleware-URL' }
if (-not $ClientId) { $ClientId = Read-Host 'Client-ID' }
if (-not $Extension) { $Extension = Read-Host 'Nebenstelle' }

$sourceHelper = Join-Path $PSScriptRoot 'kienzlefon_call.py'
$installDir = Join-Path $env:LOCALAPPDATA 'KienzlefonT2med'
$helper = Join-Path $installDir 'kienzlefon_call.py'
$config = Join-Path $env:APPDATA 'KienzlefonT2med\client.json'
$validation = Join-Path ([System.IO.Path]::GetTempPath()) ("kienzlefon-{0}.json" -f [guid]::NewGuid())
try {
    & $pythonCommand.Source @pythonArgs $sourceHelper --write-config --config $validation --url $Url --client-id $ClientId --extension $Extension
    if ($LASTEXITCODE -ne 0) { throw 'Registrierungsdaten sind ungültig.' }
} finally {
    Remove-Item -LiteralPath $validation -Force -ErrorAction SilentlyContinue
}
if ($DryRun) {
    Write-Host "Dry-run: würde Helper nach $helper und Konfiguration nach $config installieren sowie HKCU tel/callto registrieren."
    Write-Host 'Keine Registrierung und kein Telefonat wurden ausgeführt.'
    exit 0
}

New-Item -ItemType Directory -Path $installDir -Force | Out-Null
Copy-Item -LiteralPath $sourceHelper -Destination $helper -Force
& $pythonCommand.Source @pythonArgs $helper --write-config --config $config --url $Url --client-id $ClientId --extension $Extension
if ($LASTEXITCODE -ne 0) { throw 'Konfiguration konnte nicht gespeichert werden.' }

$prefix = '"' + $pythonCommand.Source + '"'
if ($pythonArgs.Count -gt 0) { $prefix += ' -3' }
$commandValue = $prefix + ' "' + $helper + '" "%1"'
$progId = 'KienzlefonT2med.Url'
$base = "HKCU:\Software\Classes\$progId"
New-Item -Path $base -Force | Out-Null
Set-Item -Path $base -Value 'Kienzlefon T2med'
New-ItemProperty -Path $base -Name 'URL Protocol' -Value '' -PropertyType String -Force | Out-Null
New-Item -Path "$base\shell\open\command" -Force | Out-Null
Set-Item -Path "$base\shell\open\command" -Value $commandValue
$capabilities = 'HKCU:\Software\KienzlefonT2med\Capabilities'
New-Item -Path "$capabilities\URLAssociations" -Force | Out-Null
New-ItemProperty -Path $capabilities -Name ApplicationName -Value 'Kienzlefon T2med' -PropertyType String -Force | Out-Null
New-ItemProperty -Path $capabilities -Name ApplicationDescription -Value 'Telefonate über die zugewiesene Praxis-Nebenstelle' -PropertyType String -Force | Out-Null
foreach ($scheme in @('tel', 'callto')) {
    New-ItemProperty -Path "$capabilities\URLAssociations" -Name $scheme -Value $progId -PropertyType String -Force | Out-Null
}
New-Item -Path 'HKCU:\Software\RegisteredApplications' -Force | Out-Null
New-ItemProperty -Path 'HKCU:\Software\RegisteredApplications' -Name 'Kienzlefon T2med' -Value 'Software\KienzlefonT2med\Capabilities' -PropertyType String -Force | Out-Null
Write-Host 'Installiert. In den Windows-Standard-Apps Kienzlefon T2med für TEL und CALLTO auswählen.'
