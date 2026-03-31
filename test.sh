#!/bin/bash
set -euo pipefail

RUN_PHPUNIT=false
RUN_PHPSTAN=false
RUN_SHELLCHECK=false
RUN_BATS=false

if [[ $# -eq 0 ]]; then
  RUN_PHPUNIT=true
  RUN_PHPSTAN=true
  RUN_SHELLCHECK=true
  RUN_BATS=true
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    -phpunit|--phpunit)
      RUN_PHPUNIT=true; shift;;
    -phpstan|--phpstan)
      RUN_PHPSTAN=true; shift;;
    -shellcheck|--shellcheck)
      RUN_SHELLCHECK=true; shift;;
    -bats|--bats)
      RUN_BATS=true; shift;;
    -h|--help)
      echo "Usage: $0 [-phpunit] [-phpstan] [-shellcheck] [-bats]"; exit 0;;
    *)
      echo "Unknown option: $1"; exit 1;;
  esac
done

if [[ "$RUN_PHPUNIT" == true ]]; then
  echo "Running PHPUnit tests..."
  php vendor/bin/phpunit --configuration phpunit.xml
  echo "PHPUnit tests passed."
fi

if [[ "$RUN_PHPSTAN" == true ]]; then
  if [[ -f vendor/bin/phpstan ]]; then
    echo "Running PHPStan static analysis..."
    php vendor/bin/phpstan analyse --memory-limit=512M
    echo "PHPStan static analysis passed."
  else
    echo "PHPStan not found. Skipping static analysis."
  fi
fi

if [[ "$RUN_SHELLCHECK" == true ]]; then
  if command -v shellcheck >/dev/null; then
    echo "Running ShellCheck..."
    shellcheck source/compose.manager/scripts/*.sh
    echo "ShellCheck passed."
  else
    echo "ShellCheck not found. Skipping shell script lint."
  fi
fi

if [[ "$RUN_BATS" == true ]]; then
  if command -v bats >/dev/null; then
    shopt -s nullglob
    bats_files=(tests/unit/*.bats)
    shopt -u nullglob

    if (( ${#bats_files[@]} )); then
      echo "Running Bats tests..."
      bats "${bats_files[@]}"
      echo "Bats tests passed."
    else
      echo "No Bats test files found. Skipping Bats tests."
    fi
  else
    echo "Bats not found. Skipping Bats tests."
  fi
fi
