<#
.SYNOPSIS
    Locadev One-Line Installer for Windows (x64)
.DESCRIPTION
    Downloads the thin repo (scripts, dashboard, config) + the Windows binary
    bundle (FrankenPHP, PHP, MariaDB) from GitHub Releases, registers 'locadev'
    on PATH, and starts everything. Re-running upgrades in place - user data
    (data/, sites/, config/sites.json) is never touched.
.EXAMPLE
    irm https://raw.githubusercontent.com/USERNAME/locadev/main/install.ps1 | iex
#>

$ErrorActionPreference = 'Stop'

# ---- Config (override via env) ----
$GhRepo    = if ($env:LOCADEV_GH)     { $env:LOCADEV_GH }     else { "bismawy/locadev" }
$Version   = if ($env:LOCADEV_VERSION){ $env:LOCADEV_VERSION } else { "latest" }
$InstallDir= if ($env:LOCADEV_HOME)   { $env:LOCADEV_HOME }   else { "$HOME\Locadev" }
$NoStart   = ($env:LOCADEV_NO_START -eq "1")
# Test overrides (file:// or alternate mirrors)
$RepoZipUrl = if ($env:LOCADEV_REPO) { $env:LOCADEV_REPO } else {
    "https://github.com/$GhRepo/releases/latest/download/locadev-repo.zip"
}
$BinZipUrl  = if ($env:LOCADEV_BIN)  { $env:LOCADEV_BIN }  else {
    "https://github.com/$GhRepo/releases/latest/download/locadev-bin-win-x64.zip"
}

function Get-RemoteFile($Url, $OutFile) {
    if ($Url -like "file://*") {
        # test path: file:///D:/dir/file.zip
        Copy-Item ($Url -replace "^file:///", "").Replace("/", "\") -Destination $OutFile
    } else {
        # TLS 1.2 for older PowerShell, ProgressBar makes IWR ~10x slower
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        $ProgressPreference = 'SilentlyContinue'
        Invoke-WebRequest -Uri $Url -OutFile $OutFile -UseBasicParsing
    }
}

Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "  Locadev - Just runs. Natively.  (installer)"      -ForegroundColor Cyan
Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "Target : $InstallDir"
Write-Host "Version: $Version"

if ($env:PROCESSOR_ARCHITECTURE -ne "AMD64") {
    Write-Host "[ERROR] Locadev Windows binaries are x64-only (detected $($env:PROCESSOR_ARCHITECTURE))." -ForegroundColor Red
    exit 1
}

# ---- Stop running services of THIS install so binaries are not locked ----
if (Test-Path "$InstallDir\bin\locadev.cmd") {
    Write-Host "[1/5] Stopping running services (upgrade-safe)..." -ForegroundColor Yellow
    & "$InstallDir\bin\locadev.cmd" stop 2>$null
} else { Write-Host "[1/5] Fresh install." -ForegroundColor Yellow }

# ---- [2/5] Repo (thin: scripts, dashboard, config, CLI) ----
$Tmp = Join-Path $env:TEMP "locadev-install-$(Get-Random)"
New-Item -ItemType Directory -Path "$Tmp","$InstallDir" -Force | Out-Null

Write-Host "[2/5] Downloading Locadev (scripts + dashboard)..." -ForegroundColor Yellow
Get-RemoteFile $RepoZipUrl "$Tmp\repo.zip"
Expand-Archive "$Tmp\repo.zip" "$Tmp\repo" -Force
# GitHub archives (and our release bundle) wrap everything in locadev-main/
if (Test-Path "$Tmp\repo\locadev-main") { $RepoSrc = "$Tmp\repo\locadev-main" } else { $RepoSrc = "$Tmp\repo" }

Write-Host "[3/5] Downloading binary bundle (FrankenPHP + PHP + MariaDB)..." -ForegroundColor Yellow
Get-RemoteFile $BinZipUrl "$Tmp\bin.zip"

# ---- [4/5] Lay down app files; PRESERVE user data ----
Write-Host "[4/5] Installing..." -ForegroundColor Yellow
# app dirs from the repo (safe to overwrite on every upgrade). config/ is excluded here and
# laid down per file below: copying the directory -Force wrote config/my.cnf on EVERY run,
# so the guard underneath never fired and user tuning was lost on every re-install.
Get-ChildItem "$RepoSrc" -Directory | Where-Object { $_.Name -notin @("data","sites","config") } |
    ForEach-Object { Copy-Item $_.FullName "$InstallDir\" -Recurse -Force }
Get-ChildItem "$RepoSrc" -File | Copy-Item -Destination "$InstallDir\" -Force
# user data dirs exist but are NOT overwritten
New-Item -ItemType Directory -Path "$InstallDir\data","$InstallDir\sites","$InstallDir\config\sites" -Force | Out-Null
# config templates only on first install (never clobber user edits)
if (-not (Test-Path "$InstallDir\config\my.cnf")) {
    Copy-Item "$RepoSrc\config\my.cnf" "$InstallDir\config\my.cnf" -ErrorAction SilentlyContinue
}
Expand-Archive "$Tmp\bin.zip" "$InstallDir\bin" -Force
Remove-Item $Tmp -Recurse -Force -ErrorAction SilentlyContinue

# ---- [5/5] PATH ----
Write-Host "[5/5] Registering 'locadev' on PATH..." -ForegroundColor Yellow
$BinDir = "$InstallDir\bin"
$UserPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($UserPath -notlike "*$BinDir*") {
    [Environment]::SetEnvironmentVariable("Path", "$UserPath;$BinDir", "User")
    Write-Host "  Added $BinDir to user PATH (restart terminal to pick it up)." -ForegroundColor Green
}

Write-Host ""
if ($NoStart) {
    Write-Host "[OK] Installed to $InstallDir (start skipped)." -ForegroundColor Green
} else {
    & "$BinDir\locadev.cmd" start
    Write-Host ""
    Write-Host "  [OK] Locadev installed and ready!" -ForegroundColor Green
    Write-Host "  Dashboard: https://localhost" -ForegroundColor Green
    Write-Host "  CLI:       locadev | locadev menu" -ForegroundColor Green
    Start-Process "https://localhost"
}
