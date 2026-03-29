#Requires -Version 7.0
<#!
.SYNOPSIS
	Build and deploy compose.manager package to a remote Unraid host.

.DESCRIPTION
	Builds (or reuses) a package, uploads it via SCP, installs it with installpkg,
	and removes the temporary package file from the remote host.

.PARAMETER Version
	Package version to build. If omitted, build.ps1 resolves from compose.manager.plg.

.PARAMETER Dev
	Generate a development build with timestamp: YYYY.MM.DD.HHmm

.PARAMETER RemoteHost
	Remote hostname(s) or IP(s). Accepts a single value or a comma-separated list.

.PARAMETER User
	SSH username.

.PARAMETER RemoteDir
	Remote directory used for upload/install.

.PARAMETER PackagePath
	Existing package path to deploy. If not set, script builds by default.

.PARAMETER SkipBuild
	Skip build and deploy latest package from archive if PackagePath is not specified.

.PARAMETER ComposeVersion
	Docker Compose version to pass through to build.ps1.

.PARAMETER AceVersion
	Ace Editor version to pass through to build.ps1.

.PARAMETER Quick
	Skip package build/install and deploy tracked staged+unstaged file changes
	from source/compose.manager directly to the remote live emhttp plugin folder.

.EXAMPLE
	./deploy.ps1 -Version "2026.03.07" -RemoteHost "saturn"

.EXAMPLE
	./deploy.ps1 -Dev -RemoteHost "saturn"

.EXAMPLE
	./deploy.ps1 -Dev -RemoteHost "saturn","jupiter"

.EXAMPLE
	./deploy.ps1 -SkipBuild -RemoteHost "saturn"

.EXAMPLE
	./deploy.ps1 -PackagePath ".\archive\compose.manager-2026.03.07-noarch-1234.txz" -RemoteHost "saturn"

.EXAMPLE
	./deploy.ps1 -Quick -RemoteHost "saturn"
#>

[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
	[string]$Version,
	[switch]$Dev,
	[string[]]$RemoteHost = @(),
	[string]$User = "root",
	[string]$RemoteDir = "/tmp",
	[string]$PackagePath,
	[switch]$SkipBuild,
	[string]$ComposeVersion = "5.0.2",
	[string]$AceVersion = "1.43.5",
	[switch]$Quick
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$scriptDir = $PSScriptRoot
$archiveDir = Join-Path $scriptDir "archive"

if ($Quick) {
	if (-not $RemoteHost -or $RemoteHost.Count -eq 0) {
		throw "RemoteHost is required when using -Quick"
	}

	if ($Version -or $Dev -or $PackagePath -or $SkipBuild) {
		Write-Host "Quick mode ignores -Version, -Dev, -PackagePath, and -SkipBuild." -ForegroundColor DarkYellow
	}

	$repoRoot = (& git -C $scriptDir rev-parse --show-toplevel 2>$null)
	if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($repoRoot)) {
		throw "Unable to resolve git repository root from $scriptDir"
	}
	$repoRoot = $repoRoot.Trim()

	$quickPrefix = "source/compose.manager/"
	$quickRemoteRoot = "/usr/local/emhttp/plugins/compose.manager"

	$statUnstaged = (& git -C $repoRoot diff --stat -- source/compose.manager)
	$statStaged = (& git -C $repoRoot diff --cached --stat -- source/compose.manager)
	if ($statUnstaged) {
		Write-Host "Unstaged tracked diff:" -ForegroundColor Cyan
		$statUnstaged | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
	}
	if ($statStaged) {
		Write-Host "Staged tracked diff:" -ForegroundColor Cyan
		$statStaged | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
	}

	$unstagedTracked = @(& git -C $repoRoot diff --name-only --diff-filter=ACMR -- source/compose.manager)
	$stagedTracked = @(& git -C $repoRoot diff --cached --name-only --diff-filter=ACMR -- source/compose.manager)

	$changedFiles = @($unstagedTracked + $stagedTracked |
		Where-Object { -not [string]::IsNullOrWhiteSpace($_) } |
		Sort-Object -Unique)

	if (-not $changedFiles -or $changedFiles.Count -eq 0) {
		Write-Host "No tracked staged/unstaged file changes found under source/compose.manager." -ForegroundColor Yellow
		return @{
			Hosts = $RemoteHost
			User = $User
			Quick = $true
			FileCount = 0
			Files = @()
			WhatIf = [bool]$WhatIfPreference
		}
	}

	Write-Host "Files queued for quick sync ($($changedFiles.Count)):" -ForegroundColor Green
	$changedFiles | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }

	$allResults = @()
	foreach ($host_ in $RemoteHost) {
		$remoteTarget = "$User@$host_"
		Write-Host "`nQuick deploy to $remoteTarget :" -ForegroundColor Green

		$syncedFiles = @()
		foreach ($relativePath in $changedFiles) {
			if (-not $relativePath.StartsWith($quickPrefix, [System.StringComparison]::Ordinal)) {
				continue
			}

			$subPath = $relativePath.Substring($quickPrefix.Length)
			if ([string]::IsNullOrWhiteSpace($subPath)) {
				continue
			}

			$localPath = Join-Path $repoRoot ($relativePath -replace '/', [IO.Path]::DirectorySeparatorChar)
			if (-not (Test-Path -Path $localPath -PathType Leaf)) {
				Write-Host "Skipping missing local file: $relativePath" -ForegroundColor DarkYellow
				continue
			}

			$remoteFile = "$quickRemoteRoot/$subPath"
			$remoteParent = ($remoteFile -replace '/[^/]+$','')

			$syncAction = "Upload changed file via SCP"
			if ($PSCmdlet.ShouldProcess("$remoteTarget`:$remoteFile", $syncAction)) {
				ssh -- "$remoteTarget" "mkdir -p '$remoteParent'"
				if ($LASTEXITCODE -ne 0) {
					throw "Failed to create remote directory $remoteParent on $host_ (exit code $LASTEXITCODE)"
				}

				scp -- "$localPath" "$remoteTarget`:$remoteFile"
				if ($LASTEXITCODE -ne 0) {
					throw "Failed to upload $relativePath to $remoteFile on $host_ (exit code $LASTEXITCODE)"
				}
			}

			$syncedFiles += $relativePath
		}

		$allResults += @{
			Host = $host_
			User = $User
			Quick = $true
			FileCount = $syncedFiles.Count
			Files = $syncedFiles
			WhatIf = [bool]$WhatIfPreference
		}
	}

	if ($WhatIfPreference) {
		Write-Host "WhatIf simulation complete (quick mode)." -ForegroundColor Green
	} else {
		Write-Host "`nQuick deployment complete to $($RemoteHost.Count) host(s)." -ForegroundColor Green
	}

	return $allResults
}

# Generate dev version with timestamp if -Dev flag is used
if ($Dev) {
	$now = Get-Date
	$Version = $now.ToString("yyyy.MM.dd.HHmm")
	Write-Host "Generated dev version: $Version" -ForegroundColor Cyan
}

function Get-LatestPackagePath {
	param([string]$Path)

	$latest = Get-ChildItem -Path $Path -Filter 'compose.manager-*-noarch-*.txz' -File |
		Sort-Object LastWriteTime -Descending |
		Select-Object -First 1

	if (-not $latest) {
		throw "No package found in $Path"
	}

	return $latest.FullName
}

if (-not $PackagePath) {
	if ($SkipBuild) {
		Write-Host "Skipping build; using latest package from archive..." -ForegroundColor Yellow
		$PackagePath = Get-LatestPackagePath -Path $archiveDir
	} else {
		$buildTarget = "local package"
		$buildAction = "Build package via build.ps1"
		if ($PSCmdlet.ShouldProcess($buildTarget, $buildAction)) {
			Write-Host "Building package..." -ForegroundColor Yellow

			$buildParams = @{
				ComposeVersion = $ComposeVersion
				AceVersion = $AceVersion
			}

			if ($Dev) {
				$buildParams.Dev = $true
			} elseif ($Version) {
				$buildParams.Version = $Version
			}

			$buildResult = & (Join-Path $scriptDir "build.ps1") @buildParams
			$buildInfo = if ($buildResult -is [array]) { $buildResult | Where-Object { $_ -is [hashtable] } | Select-Object -Last 1 } else { $buildResult }
			$PackagePath = $buildInfo.PackagePath
		} else {
			if ($Dev) {
				$now = Get-Date
				$simulatedVersion = $now.ToString("yyyy.MM.dd.HHmm")
			} elseif ($Version) {
				$simulatedVersion = $Version
			} else {
				$simulatedVersion = "<version-from-plg>"
			}
			$PackagePath = Join-Path $archiveDir "compose.manager-package-$simulatedVersion.txz"
			Write-Host "WhatIf: Simulating build output package path: $PackagePath" -ForegroundColor DarkYellow
		}
	}
} else {
	if (-not (Test-Path -Path $PackagePath -PathType Leaf)) {
		if ($WhatIfPreference) {
			Write-Host "WhatIf: PackagePath does not exist locally, continuing with simulated deploy target: $PackagePath" -ForegroundColor DarkYellow
		} else {
			throw "PackagePath does not exist: $PackagePath"
		}
	} else {
		$PackagePath = (Resolve-Path $PackagePath).Path
	}
}

$packageName = Split-Path -Leaf $PackagePath

if (-not $RemoteHost -or $RemoteHost.Count -eq 0) {
	Write-Host "No RemoteHost specified — build only, skipping deploy." -ForegroundColor Yellow
	return @{
		Hosts = @()
		User = $User
		PackagePath = $PackagePath
		WhatIf = [bool]$WhatIfPreference
	}
}

# Prefer plugin manifest generated by build.ps1 for this exact package; fallback to repository source .plg
if ($buildInfo -and $buildInfo.PluginPath -and (Test-Path -Path $buildInfo.PluginPath -PathType Leaf)) {
	$pluginPath = $buildInfo.PluginPath
} else {
	$pluginPath = Join-Path $scriptDir "compose.manager.plg"
}
if (-not (Test-Path -Path $pluginPath -PathType Leaf)) {
	throw "Plugin file not found: $pluginPath"
}
$pluginName = Split-Path -Leaf $pluginPath
$installScriptLocal = Join-Path $scriptDir "install.sh"
if (-not (Test-Path -Path $installScriptLocal -PathType Leaf)) {
	throw "Install script not found: $installScriptLocal"
}

$allResults = @()
foreach ($host_ in $RemoteHost) {
	$remoteTarget = "$User@$host_"
	$remotePackage = "$RemoteDir/$packageName"
	$remotePlugin = "$RemoteDir/$pluginName"
	$remoteInstallScript = "$RemoteDir/install.sh"

	Write-Host "`nDeploying to $remoteTarget :" -ForegroundColor Green
	Write-Host "  Local package : $PackagePath" -ForegroundColor Gray
	Write-Host "  Local .plg    : $pluginPath" -ForegroundColor Gray
	Write-Host "  Local install : $installScriptLocal" -ForegroundColor Gray
	Write-Host "  Remote target : ${remoteTarget}:$RemoteDir" -ForegroundColor Gray

	$uploadAction = "Upload package + .plg + install.sh via SCP"
	if ($PSCmdlet.ShouldProcess("${remoteTarget}:$RemoteDir/", $uploadAction)) {
		Write-Host "Uploading package, .plg and install.sh via SCP..." -ForegroundColor Yellow
		scp -- "$PackagePath" "$remoteTarget`:$RemoteDir/"
		scp -- "$pluginPath" "$remoteTarget`:$RemoteDir/"
		scp -- "$installScriptLocal" "$remoteTarget`:$remoteInstallScript"
		if ($LASTEXITCODE -ne 0) {
			throw "SCP upload to $host_ failed with exit code $LASTEXITCODE"
		}
	}

	$installAction = "Execute remote install script"
	if ($PSCmdlet.ShouldProcess($remoteTarget, $installAction)) {
		Write-Host "Executing remote install script..." -ForegroundColor Yellow
		ssh -- "$remoteTarget" "bash '$remoteInstallScript' '$remotePackage' '$remotePlugin' && rm -f '$remoteInstallScript'"
		if ($LASTEXITCODE -ne 0) {
			throw "Remote install script on $host_ failed with exit code $LASTEXITCODE"
		}
	}

	$allResults += @{
		Host = $host_
		User = $User
		PackagePath = $PackagePath
		RemotePackage = $remotePackage
		WhatIf = [bool]$WhatIfPreference
	}
}

if ($WhatIfPreference) {
	Write-Host "WhatIf simulation complete." -ForegroundColor Green
} else {
	Write-Host "`nDeployment complete to $($RemoteHost.Count) host(s)." -ForegroundColor Green
}
return $allResults