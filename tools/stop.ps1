# stop.ps1 — stop this application's server, and nothing else's.
#
# The supervisor goes first. Killing php while the supervisor is alive only
# makes it start another one, which is the whole point of the supervisor and
# exactly the wrong thing when you are trying to stop.

$root = Split-Path -Parent $PSScriptRoot

$sups = Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like '*supervisor.ps1*' }
foreach ($s in $sups) {
    Stop-Process -Id $s.ProcessId -Force -ErrorAction SilentlyContinue
}

Start-Sleep -Milliseconds 400

# Only servers serving THIS folder. PRI Facilities Request and anything else
# on this machine are left alone.
$mine = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*$root*" }
foreach ($p in $mine) {
    Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
}

Start-Sleep -Milliseconds 600

$left = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*$root*" }

Write-Host ''
if ($left) {
    Write-Host '  Something is still running. Stop it from Task Manager: php.exe.' -ForegroundColor Red
} else {
    Write-Host ('  Stopped {0} supervisor(s) and the server.' -f $sups.Count) -ForegroundColor Green
    Write-Host '  It starts again when you log on, or now with START HERE.bat.'
}
Write-Host ''
