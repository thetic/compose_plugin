
param (
    [switch] $phpunit,
    [switch] $phpstan,
    [switch] $shellcheck,
    [switch] $bats
)

$runAll = -not ($phpunit -or $phpstan -or $shellcheck -or $bats)

# Runs all PHPUnit tests and fails if any test fails
if ($runAll -or $phpunit) {
    Write-Host "Running PHPUnit tests..." -ForegroundColor Yellow
    & php vendor/bin/phpunit --configuration phpunit.xml
    if ($LASTEXITCODE -ne 0) {
        Write-Host "PHPUnit tests failed. Build aborted." -ForegroundColor Red
        exit $LASTEXITCODE
    }
    Write-Host "All PHPUnit tests passed." -ForegroundColor Green
}

# Run PHPStan static analysis
if ($runAll -or $phpstan) {
    if (Test-Path "vendor/bin/phpstan") {
        Write-Host "Running PHPStan static analysis..." -ForegroundColor Yellow
        & php vendor/bin/phpstan analyse --memory-limit=512M
        if ($LASTEXITCODE -ne 0) {
            Write-Host "PHPStan static analysis failed. Build aborted." -ForegroundColor Red
            exit $LASTEXITCODE
        }
        Write-Host "PHPStan static analysis passed." -ForegroundColor Green
    } else {
        Write-Host "PHPStan not found. Skipping static analysis." -ForegroundColor Yellow
    }
}

# Run ShellCheck for shell scripts
if ($runAll -or $shellcheck) {
    if (Get-Command shellcheck -ErrorAction SilentlyContinue) {
        Write-Host "Running ShellCheck..." -ForegroundColor Yellow
        & shellcheck source/compose.manager/scripts/*.sh
        if ($LASTEXITCODE -ne 0) {
            Write-Host "ShellCheck failed. Build aborted." -ForegroundColor Red
            exit $LASTEXITCODE
        }
        Write-Host "ShellCheck passed." -ForegroundColor Green
    } else {
        Write-Host "ShellCheck not found. Skipping shell script lint." -ForegroundColor Yellow
    }
}

# Run Bats tests in Docker
if ($runAll -or $bats) {
    $batsScript = "tests/framework/bin/run-bats.sh"
    if (Test-Path $batsScript) {
        Write-Host "Running Bats tests..." -ForegroundColor Yellow
        & bash $batsScript tests/unit
        if ($LASTEXITCODE -ne 0) {
            Write-Host "Bats tests failed. Build aborted." -ForegroundColor Red
            exit $LASTEXITCODE
        }
        Write-Host "All Bats tests passed." -ForegroundColor Green
    } else {
        Write-Host "Bats test runner not found. Skipping Bats tests." -ForegroundColor Yellow
    }
}
