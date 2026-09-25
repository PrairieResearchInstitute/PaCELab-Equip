# supervisor.ps1 — keep the web server up.
#
# The recurring "localhost refused to connect" had one cause: nothing owned the
# server. It ran only while whichever window started it stayed open, so closing
# that window, logging out, or a crash left the browser pointing at a dead port
# — and the tab looks identical whether the application is broken or simply not
# running, which is why it kept looking like a new problem.
#
# This process owns it. It starts PHP, waits, and starts it again if it stops.
# It is launched at logon from a shortcut in the Startup folder, and it can be
# stopped with STOP SERVER.bat.

param([int]$Port = 8147)

$ErrorActionPreference = 'Continue'

$root   = Split-Path -Parent $PSScriptRoot
$phpDir = Join-Path $root 'tools\php'
$php    = Join-Path $phpDir 'php.exe'
if (-not (Test-Path $php)) {
    $phpDir = Join-Path $env:LOCALAPPDATA 'php83'
    $php    = Join-Path $phpDir 'php.exe'
}

$log = Join-Path $root 'data\server.log'
New-Item -ItemType Directory -Force -Path (Split-Path $log) | Out-Null

function Note($msg) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    try { Add-Content -Path $log -Value $line -Encoding utf8 } catch { }
}

if (-not (Test-Path $php)) {
    Note "PHP not found. Looked in $root\tools\php and $env:LOCALAPPDATA\php83. Giving up."
    exit 1
}

function Backup-IfDue {
    # One a day is enough. The marker is the newest backup's date, so a machine
    # that is off overnight still gets one the next time it is on.
    try {
        $pathFile = Join-Path $root 'data\backup-path.txt'
        $dest = if (Test-Path $pathFile) { (Get-Content $pathFile -Raw).Trim() }
                else { Join-Path (Split-Path -Parent $root) 'PaCELab Equip Backups' }
        $today = Get-Date -Format 'yyyy-MM-dd'
        if (Test-Path $dest) {
            $have = Get-ChildItem $dest -Filter "lab-$today-*.sqlite" -ErrorAction SilentlyContinue
            if ($have) { return }
        }
        Note "taking the daily backup"
        & powershell -NoProfile -ExecutionPolicy Bypass -File `
            ('"' + (Join-Path $PSScriptRoot 'backup.ps1') + '"') | Out-Null
    } catch {
        Note "backup attempt failed: $($_.Exception.Message)"
    }
}

Note "supervisor started, port $Port, serving $root"

# One supervisor only. A second would fight the first for the port.
$mutex = New-Object System.Threading.Mutex($false, "Global\SharedLabEquipmentSupervisor")
if (-not $mutex.WaitOne(0)) {
    Note "another supervisor already holds the lock; exiting"
    exit 0
}

try {
    while ($true) {
        # If something else grabbed the port, wait rather than spin.
        if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
            $mine = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
                    Where-Object { $_.CommandLine -and $_.CommandLine -like "*$root*" }
            if ($mine) { Start-Sleep -Seconds 5; continue }
            Note "port $Port is held by something that is not us; waiting"
            Start-Sleep -Seconds 30
            continue
        }

        # Every path is quoted here. Start-Process does not quote for you, and
        # this folder has a space in its name, so an unquoted -t split at the
        # space and php exited 1 immediately -- which looked exactly like the
        # server "not starting" for no reason.
        $p = Start-Process -FilePath $php -PassThru -WindowStyle Hidden `
             -RedirectStandardError (Join-Path $root 'data\php-stderr.log') `
             -ArgumentList @(
                 '-c', ('"' + (Join-Path $phpDir 'php.ini') + '"'),
                 '-d', ('"extension_dir=' + (Join-Path $phpDir 'ext') + '"'),
                 '-S', "localhost:$Port",
                 '-t', ('"' + $root + '"')
             )
        Note "php started, pid $($p.Id)"

        # Wake hourly while php runs, so the supervisor can also be the thing
        # that takes the nightly backup. One process owning both means there is
        # no second scheduled job to install, break, or forget about.
        while (-not $p.HasExited) {
            $null = $p.WaitForExit(3600000)
            if (-not $p.HasExited) { Backup-IfDue }
        }

        Note "php exited with $($p.ExitCode); restarting in 3s"
        Start-Sleep -Seconds 3
        Backup-IfDue
    }
}
finally {
    $mutex.ReleaseMutex()
    Note "supervisor stopped"
}
