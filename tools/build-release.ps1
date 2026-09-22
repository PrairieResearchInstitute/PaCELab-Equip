# build-release.ps1 — Make the folder that goes to the server.
#
# Copies an explicit list of files. Nothing is excluded by pattern, because a
# pattern is a guess about what exists; a list is a decision about what ships.
# Anything added to the application later has to be added here too, and the
# build says so rather than quietly leaving it out.
#
# Refuses to build if the tests do not pass.

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
$stamp   = Get-Date -Format 'yyyy-MM-dd'
$name    = "shared-lab-equipment-$stamp"
$dist    = Join-Path $root 'dist'
$out     = Join-Path $dist $name
$php     = Join-Path $root 'tools\php\php.exe'
$phpDir  = Join-Path $root 'tools\php'

if (-not (Test-Path $php)) { $php = Join-Path $env:LOCALAPPDATA 'php83\php.exe'; $phpDir = Join-Path $env:LOCALAPPDATA 'php83' }
if (-not (Test-Path $php)) { throw "PHP was not found. Looked in tools\php and $env:LOCALAPPDATA\php83." }

# --- The tests have to pass first -------------------------------------------
Write-Host ''
Write-Host '  Running the tests before building...' -ForegroundColor Cyan
& $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" (Join-Path $root 'tests\run-tests.php') | Out-String -Stream | Select-Object -Last 3
if ($LASTEXITCODE -ne 0) {
    throw "The tests failed. Nothing was built. Fix the failures and run this again."
}

# --- What ships --------------------------------------------------------------
$files = @(
    'index.php', 'home.php', 'lab.php', 'schedule.php', 'report.php', 'api.php',
    'check.php', 'install.php', 'admin-recovery.php',
    'DEPLOY.md',
    'includes\auth.php', 'includes\db.php', 'includes\functions.php', 'includes\schema.php',
    'includes\.htaccess', 'includes\web.config',
    'assets\style.css', 'assets\app.js', 'assets\calendar.js',
    'admin\index.php', 'admin\login.php', 'admin\logout.php', 'admin\labs.php',
    'admin\equipment.php', 'admin\grants.php', 'admin\records.php', 'admin\reservations.php',
    'admin\costs.php', 'admin\emails.php', 'admin\batches.php', 'admin\units.php',
    'admin\users.php', 'admin\settings.php'
)

# --- What must never ship ----------------------------------------------------
# Named rather than merely omitted, so that a mistake is loud.
$forbidden = @('seed-demo.php', 'data', 'tools', '.git', '.claude', 'tests',
               'START HERE.bat', 'RUN TESTS.bat', 'BUILD FOR SERVER.bat',
               'FORGOT ADMIN PASSWORD.bat')

if (Test-Path $out) { Remove-Item $out -Recurse -Force }
New-Item -ItemType Directory -Path $out -Force | Out-Null

$missing = @()
foreach ($f in $files) {
    $src = Join-Path $root $f
    if (-not (Test-Path $src)) { $missing += $f; continue }
    $dst = Join-Path $out $f
    $dir = Split-Path -Parent $dst
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item $src $dst
}

if ($missing.Count -gt 0) {
    throw "These files are on the ship list but not in the project: $($missing -join ', ')"
}

# --- Anything in the project that is NOT on either list is a decision nobody
#     has made yet. Say so, rather than silently shipping or silently omitting.
$known = $files + $forbidden + @('README.docx', 'Shared_Lab_Equipment_System_Spec.docx',
                                 '.gitignore', '.gitattributes', 'dist', 'config.php')
$unaccounted = @()
foreach ($item in Get-ChildItem $root -Force) {
    if ($known -notcontains $item.Name) { $unaccounted += $item.Name }
}
$subdirs = @('includes', 'assets', 'admin')
$known  += $subdirs
foreach ($sub in $subdirs) {
    foreach ($item in Get-ChildItem (Join-Path $root $sub) -Force) {
        if ($files -notcontains "$sub\$($item.Name)") { $unaccounted += "$sub\$($item.Name)" }
    }
}
$unaccounted = $unaccounted | Where-Object { $known -notcontains $_ } | Sort-Object -Unique

# --- The zip -----------------------------------------------------------------
# Every entry is named by hand with forward slashes.
#
# Neither Compress-Archive nor ZipFile::CreateFromDirectory can be used here:
# on Windows PowerShell 5.1 both write the entry names with backslashes, which
# is not what the zip format specifies. Unzipping one of those on Linux does not
# merely warn -- it creates files literally called "includes\db.php" in one flat
# directory, so the application arrives on the server with no subdirectories at
# all and nothing works. Tested, not assumed.
$zip = Join-Path $dist "$name.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stream  = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($f in $files) {
        $entryName = "$name/" + ($f -replace '\\', '/')
        $entry  = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $writer = $entry.Open()
        $bytes  = [System.IO.File]::ReadAllBytes((Join-Path $out $f))
        $writer.Write($bytes, 0, $bytes.Length)
        $writer.Dispose()
    }
} finally {
    $archive.Dispose()
    $stream.Dispose()
}

$count = (Get-ChildItem $out -Recurse -File).Count
$size  = '{0:N0} KB' -f ((Get-ChildItem $out -Recurse -File | Measure-Object Length -Sum).Sum / 1KB)

Write-Host ''
Write-Host '  Built.' -ForegroundColor Green
Write-Host "    Folder : $out"
Write-Host "    Zip    : $zip"
Write-Host "    $count files, $size"
Write-Host ''
if ($unaccounted.Count -gt 0) {
    Write-Host '  In the project but on neither list — decide before the next build:' -ForegroundColor Yellow
    $unaccounted | ForEach-Object { Write-Host "    $_" -ForegroundColor Yellow }
    Write-Host ''
}
Write-Host '  Give the zip to IT. DEPLOY.md inside it tells them what to do.'
Write-Host ''
