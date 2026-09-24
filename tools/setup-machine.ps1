# setup-machine.ps1 — make this computer start the application by itself.
#
# The application folder travels with OneDrive. The instruction to run it at
# logon does not: a Startup shortcut lives in this computer's own user profile,
# outside the synced folder, so a new machine has the application but nothing
# telling it to run. This is the one click that fixes that, and it has to be
# run once per computer.
#
# Every path is worked out at run time, so it does not matter what the OneDrive
# folder is called on this machine or which account it is under.

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
$startup = [Environment]::GetFolderPath('Startup')
$desktop = [Environment]::GetFolderPath('Desktop')
$ws      = New-Object -ComObject WScript.Shell

Write-Host ''
Write-Host '  Shared Laboratory Equipment - set up this computer' -ForegroundColor Cyan
Write-Host '  ================================================='
Write-Host ''
Write-Host "  Application : $root"

# --- is PHP actually here, or still in the cloud? ----------------------------
$php = Join-Path $root 'tools\php\php.exe'
if (-not (Test-Path $php)) {
    Write-Host ''
    Write-Host '  PHP is missing from tools\php.' -ForegroundColor Red
    Write-Host '  If this folder is on OneDrive it has probably not finished downloading.'
    Write-Host '  Right-click the folder, choose "Always keep on this device", wait for the'
    Write-Host '  green ticks, and run this again.'
    Write-Host ''
    Read-Host '  Press Enter to close'
    exit 1
}
$offline = (Get-Item $php).Attributes.ToString() -match 'Offline'
if ($offline) {
    Write-Host ''
    Write-Host '  PHP is here but not downloaded yet (OneDrive is holding it in the cloud).' -ForegroundColor Yellow
    Write-Host '  Right-click the folder, choose "Always keep on this device", and wait for'
    Write-Host '  the green ticks before relying on this.'
}

# --- start at logon ----------------------------------------------------------
$lnk = $ws.CreateShortcut((Join-Path $startup 'Shared Lab Equipment.lnk'))
$lnk.TargetPath       = 'powershell.exe'
$lnk.Arguments        = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' +
                        (Join-Path $root 'tools\ensure-running.ps1') + '"'
$lnk.WorkingDirectory = $root
$lnk.WindowStyle      = 7
$lnk.Description      = 'Keeps the Shared Laboratory Equipment server running'
$lnk.Save()
Write-Host "  Starts at logon : yes  ($startup)"

# --- something to click ------------------------------------------------------
$d = $ws.CreateShortcut((Join-Path $desktop 'Shared Lab Equipment.lnk'))
$d.TargetPath       = Join-Path $root 'START HERE.bat'
$d.WorkingDirectory = $root
$d.Description      = 'Open the Shared Laboratory Equipment system'
$d.Save()
Write-Host "  Desktop shortcut: yes  ($desktop)"

# --- and start it now --------------------------------------------------------
Write-Host ''
Write-Host '  Starting it...'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'tools\ensure-running.ps1')
if ($LASTEXITCODE -eq 0) {
    Write-Host '  Running at http://localhost:8147/' -ForegroundColor Green
} else {
    Write-Host '  It did not start. See data\server.log.' -ForegroundColor Red
}

# --- the thing that will actually bite ---------------------------------------
$db = Join-Path $root 'data\lab.sqlite'
if (Test-Path $db) {
    Write-Host ''
    Write-Host '  One warning worth reading' -ForegroundColor Yellow
    Write-Host '  -------------------------'
    Write-Host '  The database is inside the OneDrive folder, so it syncs between your'
    Write-Host '  computers. That is fine while only one computer is using it at a time.'
    Write-Host '  Running it on two at once can produce OneDrive conflict copies and lose'
    Write-Host '  charges, because a database being written to is not a file sync should'
    Write-Host '  be copying. Use one machine at a time until IT hosts it properly, and'
    Write-Host '  then everybody uses the one address instead of this folder.'
}

# --- has that already happened? ----------------------------------------------
$conflicts = Get-ChildItem (Join-Path $root 'data') -Filter '*lab*' -ErrorAction SilentlyContinue |
             Where-Object { $_.Name -ne 'lab.sqlite' -and $_.Name -like '*lab*.sqlite*' }
if ($conflicts) {
    Write-Host ''
    Write-Host '  OneDrive has already made conflict copies of the database:' -ForegroundColor Red
    $conflicts | ForEach-Object { Write-Host "    $($_.Name)" }
    Write-Host '  Work out which is current before using the application further.'
}

Write-Host ''
Read-Host '  Press Enter to close'
