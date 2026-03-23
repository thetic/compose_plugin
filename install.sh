#!/bin/bash
set -euo pipefail

PACKAGE_TAR="$1"
PLUGIN_FILE="$2"

if [ -z "$PACKAGE_TAR" ] || [ -z "$PLUGIN_FILE" ]; then
  echo "Usage: $0 <package_tar> <plugin_plg>"
  exit 2
fi

# This script is expected to run on the remote unRAID host.
mkdir -p /boot/config/plugins
cp -- "$PLUGIN_FILE" "/boot/config/plugins/$(basename "$PLUGIN_FILE")"

if plugin install "/boot/config/plugins/$(basename "$PLUGIN_FILE")"; then
  echo "plugin install succeeded"
else
  echo "plugin install failed, falling back to upgradepkg"
  upgradepkg --install-new "$PACKAGE_TAR"
fi
