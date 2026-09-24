# ensure-running.ps1 — make sure the application is up, then optionally open it.
#
# Safe to run any number of times. If the supervisor is already running it does
# nothing but open the browser, so double-clicking the launcher twice can never
# start two servers or take a second port.

param([switch]$Open, [int]$Port = 8147)

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
$url  = "http://localhost:$Port/home.php"

function Serving {
    try {
        Invoke-WebRequest "http://localhost:$Port/index.php" -TimeoutSec 2 -UseBasicParsing | Out-Null
        return $true
    } catch {
        # a redirect or an error page still means something is answering
        return [bool]$_.Exception.Response
    }
}

if (-not (Serving)) {
    # Quoted, for the same reason the supervisor quotes its own arguments: this
    # folder has a space in its name and Start-Process does no quoting, so an
    # unquoted -File never found the script and nothing was logged at all.
    $sup = Join-Path $PSScriptRoot 'supervisor.ps1'
    Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', ('"' + $sup + '"'),
        '-Port', "$Port"
    ) | Out-Null

    foreach ($i in 1..40) {
        Start-Sleep -Milliseconds 250
        if (Serving) { break }
    }
}

if (Serving) {
    if ($Open) { Start-Process $url }
    exit 0
}

Write-Host ''
Write-Host '  The server did not come up.' -ForegroundColor Red
Write-Host "  Look at data\server.log in $root for the reason."
Write-Host ''
if ($Open) { Read-Host '  Press Enter to close' }
exit 1
