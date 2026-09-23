# start-server.ps1 — Start this application's web server and open it.
#
# Why this is not four lines of batch:
#
# The first version checked whether anything was listening on port 8080 and, if
# so, assumed that had to be us and pointed the browser at it. It was not us. It
# was PRI Facilities Request, which serves its own application on 8080, so the
# browser opened somebody else's site and answered "404 /home.php was not found"
# — a message that tells you nothing about the actual problem. Several sessions
# were lost to that.
#
# So: find OUR server if it is already running, by looking for a php process
# serving THIS folder, and go to that. Otherwise take a free port and start one.
# Never assume a stranger on a port is a friend.

$ErrorActionPreference = 'Stop'

$root   = Split-Path -Parent $PSScriptRoot
$php    = Join-Path $root 'tools\php\php.exe'
$phpDir = Join-Path $root 'tools\php'

if (-not (Test-Path $php)) {
    $phpDir = Join-Path $env:LOCALAPPDATA 'php83'
    $php    = Join-Path $phpDir 'php.exe'
}
if (-not (Test-Path $php)) {
    Write-Host ''
    Write-Host '  PHP was not found. Looked in:' -ForegroundColor Red
    Write-Host "    $root\tools\php\php.exe"
    Write-Host "    $env:LOCALAPPDATA\php83\php.exe"
    Write-Host ''
    Write-Host '  If this folder is on OneDrive it may not have finished downloading.'
    Write-Host '  Right-click the folder, choose "Always keep on this device", wait for'
    Write-Host '  the green checks, and start this again.'
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}

# --- Is OUR server already up? ----------------------------------------------
# Matched on the folder being served, not on the port. Another application's
# server is not ours however familiar its port looks.
$mine = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*$root*" -and $_.CommandLine -like '*-S *' }

if ($mine) {
    $running = $null
    foreach ($m in $mine) {
        if ($m.CommandLine -match '-S\s+\S*?:(\d+)') { $running = $Matches[1]; break }
    }
    if ($running) {
        Write-Host ''
        Write-Host "  Already running on port $running. Opening the browser." -ForegroundColor Green
        Write-Host ''
        Start-Process "http://localhost:$running/home.php"
        Start-Sleep -Seconds 2
        exit 0
    }
}

# --- Take a port nobody else is on -------------------------------------------
# 8080 is left alone deliberately: it is the obvious choice, which is exactly
# why something else is usually sitting on it.
function Port-Free([int]$p) {
    return -not (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue)
}

$port = 0
foreach ($candidate in 8147..8157) {
    if (Port-Free $candidate) { $port = $candidate; break }
}
if ($port -eq 0) {
    Write-Host '  Every port from 8147 to 8157 is busy. Close something and try again.' -ForegroundColor Red
    Read-Host '  Press Enter to close'
    exit 1
}

$url = "http://localhost:$port/home.php"

# --- Say who else is about, so a clash is never a mystery again --------------
$others = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
          Where-Object { $_.CommandLine -and $_.CommandLine -notlike "*$root*" -and $_.CommandLine -like '*-S *' }

Write-Host ''
Write-Host '  Shared Laboratory Equipment System' -ForegroundColor Cyan
Write-Host '  =================================='
Write-Host ''
Write-Host "  Open at : $url"
Write-Host "  Serving : $root"
Write-Host "  PHP     : $php"
if ($others) {
    Write-Host ''
    Write-Host '  Also running on this computer (left alone):' -ForegroundColor DarkGray
    foreach ($o in $others) {
        $p = if ($o.CommandLine -match '-S\s+\S*?:(\d+)') { $Matches[1] } else { '?' }
        $f = if ($o.CommandLine -match '-t\s+"([^"]+)"') { Split-Path -Leaf $Matches[1] } else { 'unknown' }
        Write-Host "    port $p  $f" -ForegroundColor DarkGray
    }
}
Write-Host ''
Write-Host '  KEEP THIS WINDOW OPEN. Closing it stops the server.' -ForegroundColor Yellow
Write-Host ''

Start-Process $url

& $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" -S "localhost:$port" -t $root
