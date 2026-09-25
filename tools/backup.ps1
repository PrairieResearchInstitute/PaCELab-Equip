# backup.ps1 — a consistent copy of the database, taken while it is in use.
#
# Not a file copy. Copying a SQLite file that is being written produces a file
# that usually opens and is occasionally torn, which is the worst kind of
# backup: it looks fine until the day you need it. VACUUM INTO asks SQLite for
# a complete, consistent database at a point in time, and is safe with the
# application running.
#
# The copy is then opened and counted before it is kept. A backup nobody has
# read is a guess.
#
#     powershell -File tools\backup.ps1            normal nightly copy
#     powershell -File tools\backup.ps1 -Restore   prove one can be restored
#
# Destination: the path in data\backup-path.txt if present, otherwise a folder
# beside the application. Point it at Taiga once this is on the server.

param([switch]$Restore, [int]$KeepDays = 60)

$ErrorActionPreference = 'Stop'

$root   = Split-Path -Parent $PSScriptRoot
$phpDir = Join-Path $root 'tools\php'
$php    = Join-Path $phpDir 'php.exe'
if (-not (Test-Path $php)) { $phpDir = Join-Path $env:LOCALAPPDATA 'php83'; $php = Join-Path $phpDir 'php.exe' }

$db = Join-Path $root 'data\lab.sqlite'
if (-not (Test-Path $db)) { throw "No database at $db" }

$pathFile = Join-Path $root 'data\backup-path.txt'
if (Test-Path $pathFile) {
    $dest = (Get-Content $pathFile -Raw).Trim()
} else {
    $dest = Join-Path (Split-Path -Parent $root) 'PaCELab Equip Backups'
}
New-Item -ItemType Directory -Force -Path $dest | Out-Null

$log = Join-Path $root 'data\backup.log'
function Note($m) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m
    try { Add-Content -Path $log -Value $line -Encoding utf8 } catch { }
    Write-Host "  $m"
}

# Run a snippet of PHP against a database and return its output.
function Sql($file, $code) {
    $tmp = [IO.Path]::GetTempFileName() + '.php'
    $body = '<?php $p = new PDO("sqlite:" . $argv[1]); ' +
            '$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); ' + $code
    [IO.File]::WriteAllText($tmp, $body, (New-Object Text.UTF8Encoding $false))
    try {
        & $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" $tmp $file 2>&1
    } finally {
        Remove-Item $tmp -Force -ErrorAction SilentlyContinue
    }
}

# The tables whose row counts must survive a round trip.
$TABLES = 'labs','lab_members','equipment','grants','usage_records','reservations',
          'equipment_costs','email_log','export_batches','admin_users','settings'

function Counts($file) {
    $code = '$t = explode(",", $argv[2] ?? ""); foreach ($t as $n) { ' +
            'echo $n, "=", $p->query("SELECT COUNT(*) FROM " . $n)->fetchColumn(), ";"; }'
    $tmp = [IO.Path]::GetTempFileName() + '.php'
    [IO.File]::WriteAllText($tmp, ('<?php $p = new PDO("sqlite:" . $argv[1]); ' + $code), (New-Object Text.UTF8Encoding $false))
    try {
        $out = & $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" `
               $tmp $file ($TABLES -join ',') 2>&1
        return ($out -join '')
    } finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
}


if ($Restore) {
    # --- the drill -----------------------------------------------------------
    $newest = Get-ChildItem $dest -Filter 'lab-*.sqlite' -ErrorAction SilentlyContinue |
              Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $newest) { throw "No backup found in $dest" }

    Note "restore drill using $($newest.Name)"
    $scratch = Join-Path ([IO.Path]::GetTempPath()) ("restore-drill-{0}.sqlite" -f $PID)
    Copy-Item $newest.FullName $scratch -Force

    $live = Counts $db
    $back = Counts $scratch
    Remove-Item $scratch -Force -ErrorAction SilentlyContinue

    Note "live    : $live"
    Note "restored: $back"
    if ($live -eq $back) {
        Note "RESTORE DRILL PASSED - the backup holds the same rows as the live database"
        exit 0
    }
    Note "RESTORE DRILL FAILED - the counts differ. Do not trust this backup."
    exit 1
}


# --- take one ----------------------------------------------------------------
$stamp = Get-Date -Format 'yyyy-MM-dd-HHmm'
$out   = Join-Path $dest "lab-$stamp.sqlite"

# VACUUM INTO refuses to overwrite, which is the behaviour we want.
if (Test-Path $out) { Remove-Item $out -Force }
$null = Sql $db ('$p->exec("VACUUM INTO " . $p->quote($argv[2]));') 2>&1
$tmp = [IO.Path]::GetTempFileName() + '.php'
[IO.File]::WriteAllText($tmp, '<?php $p = new PDO("sqlite:" . $argv[1]); $p->exec("VACUUM INTO " . $p->quote($argv[2]));', (New-Object Text.UTF8Encoding $false))
try {
    & $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" $tmp $db $out
} finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }

if (-not (Test-Path $out)) { Note "BACKUP FAILED - nothing was written"; exit 1 }

# --- read it back before believing in it -------------------------------------
$live = Counts $db
$back = Counts $out
if ($live -ne $back) {
    Note "BACKUP REJECTED - counts differ. live=$live backup=$back"
    Remove-Item $out -Force -ErrorAction SilentlyContinue
    exit 1
}

$kb = [math]::Round((Get-Item $out).Length / 1KB)
Note "backup ok: $($out) ($kb KB) - $live"

# --- prune -------------------------------------------------------------------
$cut = (Get-Date).AddDays(-$KeepDays)
$old = Get-ChildItem $dest -Filter 'lab-*.sqlite' | Where-Object { $_.LastWriteTime -lt $cut }
foreach ($o in $old) { Remove-Item $o.FullName -Force -ErrorAction SilentlyContinue }
if ($old) { Note "pruned $($old.Count) backup(s) older than $KeepDays days" }
exit 0
