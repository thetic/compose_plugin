#Requires -Version 7.0
<#
.SYNOPSIS
    Creates a release tag for the compose.manager plugin.

.DESCRIPTION
    This script creates and pushes a version tag which triggers GitHub Actions
    to build the package and create a release. Uses date-based versioning (YYYY.MM.DD).
    
    Stable releases: v2026.02.01, v2026.02.01a, v2026.02.01b
    Beta releases:   v2026.02.01-dev (use -Beta flag)
    
    Automatically generates release notes from git commits and updates the PLG file.

.PARAMETER Beta
    Create a beta/dev release (adds -dev suffix, marks as prerelease on GitHub).

.PARAMETER DryRun
    Show what would be done without making any changes.

.PARAMETER Force
    Skip all confirmation prompts.

.EXAMPLE
    ./release.ps1           # Creates v2026.02.01 (stable)
    ./release.ps1 -Beta     # Creates v2026.02.01-dev (beta)
    ./release.ps1 -DryRun   # Preview without changes
    ./release.ps1 -Force    # Skip confirmations
#>

param(
    [switch]$Beta,
    [switch]$DryRun,
    [switch]$Force
)

$ErrorActionPreference = "Stop"
$GitHubRepo = "mstrhakr/compose_plugin"
$PlgFile = "compose.manager.plg"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
if ($Beta) {
    Write-Host "  Compose Manager BETA Release" -ForegroundColor Yellow
} else {
    Write-Host "  Compose Manager Release Script" -ForegroundColor Cyan
}
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Get today's date in version format
$dateVersion = Get-Date -Format "yyyy.MM.dd"

if ($Beta) {
    # Beta releases use -dev.HHMM suffix for uniqueness
    $timeStamp = Get-Date -Format "HHmm"
    $newTag = "v$dateVersion-dev.$timeStamp"
    
    # No collision check needed - time-based tags are unique
    git fetch origin --tags
} else {
    # Stable release logic
    $baseTag = "v$dateVersion"
    
    # Fetch latest tags from remote
    Write-Host "Fetching latest from origin..." -ForegroundColor Yellow
    git fetch origin --tags
    
    # Get existing tags for today (exclude -dev tags)
    $existingTags = git tag -l "$baseTag*" 2>$null | Where-Object { $_ -notmatch '-dev' } | Sort-Object
    
    if ($existingTags) {
        Write-Host "Existing tags for today:" -ForegroundColor Yellow
        $existingTags | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
        
        # Find the next suffix
        $lastTag = $existingTags | Select-Object -Last 1
        
        if ($lastTag -eq $baseTag) {
            # First release was without suffix, next is 'a'
            $newTag = "${baseTag}a"
        } elseif ($lastTag -match "^v\d{4}\.\d{2}\.\d{2}([a-z])$") {
            # Increment the suffix letter
            $lastSuffix = $matches[1]
            $nextSuffix = [char]([int][char]$lastSuffix + 1)
            if ($nextSuffix -gt 'z') {
                Write-Error "Too many releases today! (exceeded 'z' suffix)"
                exit 1
            }
            $newTag = "$baseTag$nextSuffix"
        } else {
            Write-Error "Unexpected tag format: $lastTag"
            exit 1
        }
    } else {
        # No releases today yet - use base tag without suffix
        $newTag = $baseTag
    }
}

# Get the last tag for generating changelog
$lastTag = git describe --tags --abbrev=0 2>$null
$versionNumber = $newTag -replace '^v', ''

Write-Host ""
Write-Host "New release tag: " -NoNewline
if ($Beta) {
    Write-Host $newTag -ForegroundColor Yellow
    Write-Host "  Type: BETA (will update dev branch)" -ForegroundColor Yellow
} else {
    Write-Host $newTag -ForegroundColor Green
    Write-Host "  Type: STABLE (will update main branch)" -ForegroundColor Green
}
Write-Host ""

# Generate release notes from git commits
Write-Host "Generating release notes..." -ForegroundColor Yellow

if ($lastTag) {
    Write-Host "  Changes since $lastTag" -ForegroundColor Gray
    $commitRange = "$lastTag..HEAD"
} else {
    Write-Host "  All commits (no previous tag found)" -ForegroundColor Gray
    $commitRange = "HEAD"
}

# Get commit messages with full body, using a delimiter to separate commits
$commitDelimiter = "---COMMIT-SEPARATOR---"
$rawCommits = git log $commitRange --pretty=format:"%s%n%b$commitDelimiter" --no-merges 2>$null

# Split into individual commits and parse
$commitBlocks = ($rawCommits -join "`n") -split [regex]::Escape($commitDelimiter) | Where-Object { $_.Trim() }

# Category definitions for conventional commits
$categoryTitles = @{
    'feat' = 'Features'
    'fix' = 'Bug Fixes'
    'docs' = 'Documentation'
    'style' = 'Styles'
    'refactor' = 'Refactoring'
    'perf' = 'Performance'
    'test' = 'Tests'
    'build' = 'Build'
    'ci' = 'CI/CD'
    'chore' = 'Chores'
}

# Parse commits into categorized structure
$categorizedCommits = @{}
$uncategorizedMajor = @()
$uncategorizedMinor = @()

foreach ($block in $commitBlocks) {
    $lines = @($block.Trim() -split "`n" | Where-Object { $_.Trim() })
    if ($lines.Count -eq 0) { continue }
    
    $subject = $lines[0].Trim()
    
    # Filter out noise
    if (-not $subject -or 
        $subject -match "^Merge " -or
        $subject -match "^v\d{4}\." -or
        $subject -match "^Release v" -or
        $subject -match "^Update changelog" -or
        $subject -match "^\[skip ci\]") {
        continue
    }
    
    # Extract bullet points from body (lines starting with -)
    $bodyBullets = @()
    for ($i = 1; $i -lt $lines.Count; $i++) {
        $line = $lines[$i].Trim()
        if ($line -match "^-\s*(.+)$") {
            $bodyBullets += $matches[1].Trim()
        }
    }
    
    # Parse conventional commit format: type(scope): message
    $type = $null
    $scope = $null
    $message = $subject
    
    if ($subject -match '^(\w+)(?:\(([^)]+)\))?:\s*(.+)$') {
        $type = $matches[1].ToLower()
        $scope = $matches[2]
        $message = $matches[3].Trim()
    }
    
    $commitObj = @{
        Subject = $subject
        Type = $type
        Scope = $scope
        Message = $message
        Bullets = $bodyBullets
        IsMajor = ($bodyBullets.Count -gt 0)
    }
    
    if ($type -and $categoryTitles.ContainsKey($type)) {
        # Categorized commit
        $categoryKey = $type
        if ($scope) {
            $categoryKey = "$type|$scope"
        }
        if (-not $categorizedCommits.ContainsKey($categoryKey)) {
            $categorizedCommits[$categoryKey] = @()
        }
        $categorizedCommits[$categoryKey] += $commitObj
    } else {
        # Uncategorized commit
        if ($commitObj.IsMajor) {
            $uncategorizedMajor += $commitObj
        } else {
            $uncategorizedMinor += $commitObj
        }
    }
}

# Build release notes for PLG
$releaseNotes = @()
$releaseNotes += "###$versionNumber"

$hasContent = $false

# Group by type, then by scope within type
$typeOrder = @('feat', 'fix', 'perf', 'refactor', 'docs', 'style', 'test', 'build', 'ci', 'chore')

foreach ($type in $typeOrder) {
    # Get all keys for this type (with and without scopes)
    $typeKeys = $categorizedCommits.Keys | Where-Object { $_ -eq $type -or $_ -like "$type|*" } | Sort-Object
    
    if ($typeKeys.Count -eq 0) { continue }
    
    $hasContent = $true
    $typeTitle = $categoryTitles[$type]
    
    foreach ($key in $typeKeys) {
        $commits = $categorizedCommits[$key]
        $scope = $null
        if ($key -match '\|(.+)$') {
            $scope = $matches[1]
        }
        
        foreach ($commit in $commits) {
            # Build the line with optional scope prefix
            if ($scope) {
                $line = "- $typeTitle ($scope): $($commit.Message)"
            } else {
                $line = "- $typeTitle`: $($commit.Message)"
            }
            $releaseNotes += $line
            
            # Add bullet points for major commits
            foreach ($bullet in $commit.Bullets) {
                $releaseNotes += "  - $bullet"
            }
        }
    }
}

# Add uncategorized major commits
foreach ($commit in $uncategorizedMajor) {
    $hasContent = $true
    $releaseNotes += "- $($commit.Subject)"
    foreach ($bullet in $commit.Bullets) {
        $releaseNotes += "  - $bullet"
    }
}

# Add uncategorized minor commits
foreach ($commit in $uncategorizedMinor) {
    $hasContent = $true
    $releaseNotes += "- $($commit.Subject)"
}

if (-not $hasContent) {
    $releaseNotes += "- Minor updates and improvements"
}

# Add link to GitHub comparison
$releaseNotes += "- [View all changes](https://github.com/$GitHubRepo/compare/$lastTag...$newTag)"

Write-Host ""
Write-Host "Release notes:" -ForegroundColor Cyan
$releaseNotes | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
Write-Host ""

# Update PLG file with new release notes
function Update-PlgChangelog {
    param (
        [string]$PlgPath,
        [string[]]$NewNotes
    )
    
    $content = Get-Content $PlgPath -Raw
    
    # XML-escape special characters to avoid breaking the PLG XML
    $escape = {
        param($s)
        if ($null -eq $s) { return $s }
        $s = $s -replace '&','&amp;'
        $s = $s -replace '<','&lt;'
        $s = $s -replace '>','&gt;'
        return $s
    }
    $escapedNotes = $NewNotes | ForEach-Object { & $escape $_ }
    
    # Replace the entire contents of the <CHANGES> block with the new, escaped notes
    $pattern = '(?s)(<CHANGES>\r?\n).*?(\r?\n</CHANGES>)'
    $newContent = [regex]::Replace($content, $pattern, { param($m) 
        $head = $m.Groups[1].Value
        $tail = $m.Groups[2].Value
        return $head + ($escapedNotes -join "`n") + "`n" + $tail
    })
    
    return $newContent
}

if (-not $DryRun) {
    Write-Host "Updating $PlgFile with release notes..." -ForegroundColor Cyan
    $newPlgContent = Update-PlgChangelog -PlgPath $PlgFile -NewNotes $releaseNotes
    $newPlgContent | Set-Content $PlgFile -NoNewline
    
    # Stage and commit the PLG update
    git add $PlgFile
    $commitResult = git commit -m "Release $newTag" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  Committed changelog update" -ForegroundColor Green
    } else {
        Write-Host "  No changes to commit (changelog may already be up to date)" -ForegroundColor Yellow
    }
}

# Check for uncommitted changes
$status = git status --porcelain
if ($status) {
    Write-Host "Warning: You have uncommitted changes:" -ForegroundColor Yellow
    $status | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
    Write-Host ""
    
    if (-not $Force) {
        $response = Read-Host "Continue anyway? (y/N)"
        if ($response -ne 'y' -and $response -ne 'Y') {
            Write-Host "Aborted." -ForegroundColor Red
            exit 1
        }
    }
}

# Check if we're on main branch
$currentBranch = git branch --show-current
if ($currentBranch -ne 'main') {
    Write-Host "Warning: You're on branch '$currentBranch', not 'main'" -ForegroundColor Yellow
    
    if (-not $Force) {
        $response = Read-Host "Continue anyway? (y/N)"
        if ($response -ne 'y' -and $response -ne 'Y') {
            Write-Host "Aborted." -ForegroundColor Red
            exit 1
        }
    }
}

# Check if local is behind remote
$behind = git rev-list --count "HEAD..origin/$currentBranch" 2>$null
if ($behind -gt 0) {
    Write-Host "Warning: Local branch is $behind commit(s) behind origin/$currentBranch" -ForegroundColor Yellow
    
    if (-not $Force) {
        $response = Read-Host "Pull changes first? (Y/n)"
        if ($response -ne 'n' -and $response -ne 'N') {
            git pull origin $currentBranch
        }
    }
}

if ($DryRun) {
    Write-Host ""
    Write-Host "[DRY RUN] Would execute:" -ForegroundColor Magenta
    Write-Host "  1. Update $PlgFile with release notes" -ForegroundColor Gray
    Write-Host "  2. git commit -m `"Release $newTag`"" -ForegroundColor Gray
    Write-Host "  3. git tag $newTag" -ForegroundColor Gray
    Write-Host "  4. git push origin $currentBranch" -ForegroundColor Gray
    Write-Host "  5. git push origin $newTag" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Run without -DryRun to create the release." -ForegroundColor Cyan
    exit 0
}

# Confirm release
if (-not $Force) {
    Write-Host ""
    $response = Read-Host "Create and push tag '$newTag'? (y/N)"
    if ($response -ne 'y' -and $response -ne 'Y') {
        Write-Host "Aborted." -ForegroundColor Red
        exit 1
    }
}

# Push any pending commits first
Write-Host ""
Write-Host "Pushing commits to origin/$currentBranch..." -ForegroundColor Cyan
git push origin $currentBranch

# Create and push the tag
Write-Host ""
Write-Host "Creating tag $newTag..." -ForegroundColor Cyan
git tag $newTag

Write-Host "Pushing tag to origin..." -ForegroundColor Cyan
git push origin $newTag

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Release $newTag initiated!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "GitHub Actions will now:" -ForegroundColor Cyan
Write-Host "  1. Build the TXZ package" -ForegroundColor Gray
Write-Host "  2. Calculate MD5 hash" -ForegroundColor Gray
Write-Host "  3. Create GitHub Release" -ForegroundColor Gray
Write-Host "  4. Update PLG in main branch" -ForegroundColor Gray
Write-Host ""
Write-Host "Monitor progress at:" -ForegroundColor Cyan
Write-Host "  https://github.com/$GitHubRepo/actions" -ForegroundColor Blue
Write-Host ""
Write-Host "Release will be available at:" -ForegroundColor Cyan
Write-Host "  https://github.com/$GitHubRepo/releases/tag/$newTag" -ForegroundColor Blue
Write-Host ""
