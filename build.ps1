#Requires -Version 7.0
<#
.SYNOPSIS
    Builds the compose.manager plugin package using Docker.

.DESCRIPTION
    This script runs a Slackware Docker container to build the .txz package.
    It downloads Docker Compose, Compose Switch, and Ace Editor, then packages
    everything together.

.PARAMETER Version
    The version string for the package (e.g., "0.1.0"). Defaults to version in .plg file.

.PARAMETER Dev
    Generate a development build with timestamp: YYYY.MM.DD-dev-HHMMSS

.PARAMETER ComposeVersion
    Docker Compose version to include. Default: 2.40.3

.PARAMETER AceVersion
    Ace Editor version to include. Default: 1.4.14

.EXAMPLE
    ./build.ps1
    ./build.ps1 -Version "0.2.0"
    ./build.ps1 -Dev
    ./build.ps1 -ComposeVersion "2.41.0"
#>

param(
    [string]$Version,
    [switch]$Dev,
    [string]$ComposeVersion,
    [string]$AceVersion
)

$ErrorActionPreference = "Stop"
$ScriptDir = $PSScriptRoot

# Read component versions from versions.env if not supplied as parameters
$versionsFile = Join-Path $ScriptDir "versions.env"
if (Test-Path $versionsFile) {
    Get-Content $versionsFile | ForEach-Object {
        if ($_ -match '^COMPOSE_VERSION=(.+)$' -and -not $ComposeVersion) { $ComposeVersion = $Matches[1].Trim() }
        if ($_ -match '^ACE_VERSION=(.+)$' -and -not $AceVersion) { $AceVersion = $Matches[1].Trim() }
    }
}
if (-not $ComposeVersion) { $ComposeVersion = "5.0.2" }
if (-not $AceVersion) { $AceVersion = "1.43.5" }

# Generate dev version with timestamp if -Dev flag is used
if ($Dev) {
    $now = Get-Date
    $Version = $now.ToString("yyyy.MM.dd") + "-dev." + $now.ToString("HHmm")
    Write-Host "Generated dev version: $Version" -ForegroundColor Cyan
}

# If no version specified, read from .plg file
if (-not $Version) {
    $plgContent = Get-Content "$ScriptDir\compose.manager.plg" -Raw
    if ($plgContent -match 'ENTITY version\s+"([^"]+)"') {
        $Version = $Matches[1]
        Write-Host "Using version from .plg file: $Version" -ForegroundColor Cyan
    } else {
        throw "Could not determine version. Please specify -Version parameter."
    }
}

$PackageName = "compose.manager-package-$Version.txz"
$OutputPath = "$ScriptDir\archive"

# Ensure output directory exists
if (-not (Test-Path $OutputPath)) {
    New-Item -ItemType Directory -Path $OutputPath -Force | Out-Null
}

Write-Host "Building compose.manager package v$Version" -ForegroundColor Green
Write-Host "  Docker Compose: v$ComposeVersion" -ForegroundColor Gray
Write-Host "  Ace Editor: v$AceVersion" -ForegroundColor Gray
Write-Host ""

# Convert Windows paths to Docker-compatible paths
$ArchivePath = $OutputPath -replace '\\', '/' -replace '^([A-Za-z]):', '/$1'
$SourcePath = "$ScriptDir/source" -replace '\\', '/' -replace '^([A-Za-z]):', '/$1'

# Build in Docker
$dockerArgs = @(
    "run", "--rm", "--tmpfs", "/tmp"
    "-v", "${ArchivePath}:/mnt/output:rw"
    "-v", "${SourcePath}:/mnt/source:ro"
    "-e", "TZ=America/New_York"
    "-e", "COMPOSE_VERSION=$ComposeVersion"
    "-e", "ACE_VERSION=$AceVersion"
    "-e", "OUTPUT_FOLDER=/mnt/output"
    "-e", "PKG_VERSION=$Version"
    "vbatts/slackware:latest"
    "/mnt/source/pkg_build.sh"
)

Write-Host "Running Docker build..." -ForegroundColor Yellow
docker @dockerArgs

if ($LASTEXITCODE -ne 0) {
    throw "Docker build failed with exit code $LASTEXITCODE"
}

$PackagePath = Join-Path $OutputPath $PackageName
if (Test-Path $PackagePath) {
    # Calculate MD5
    $md5 = (Get-FileHash -Path $PackagePath -Algorithm MD5).Hash.ToLower()

    Write-Host ""
    Write-Host "Build successful!" -ForegroundColor Green
    Write-Host "  Package: $PackagePath" -ForegroundColor Cyan
    Write-Host "  MD5: $md5" -ForegroundColor Cyan

    # Return build info for use by release script
    return @{
        Version = $Version
        PackagePath = $PackagePath
        PackageName = $PackageName
        MD5 = $md5
        ComposeVersion = $ComposeVersion
        AceVersion = $AceVersion
    }
} else {
    throw "Package was not created: $PackagePath"
}
