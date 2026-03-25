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
echo "uninstall plugin if it exists"
if plugin remove compose.manager; then
  echo "plugin remove succeeded"
else
  echo "plugin remove failed, plugin may not have been installed"
fi

if removepkg compose.manager; then
  echo "removepkg succeeded"
else
  echo "removepkg failed, plugin may not have been installed"
fi

if [ -d "/usr/local/emhttp/plugins/compose.manager" ]; then
  echo "Removing existing plugin directory at /usr/local/emhttp/plugins/compose.manager"
  rm -rf /usr/local/emhttp/plugins/compose.manager
fi

if compgen -G "/boot/config/plugins/compose.manager/*.txz" > /dev/null; then
  echo "Removing existing plugin packages at /boot/config/plugins/compose.manager"
  rm -f /boot/config/plugins/compose.manager/*.txz
fi

cp -- "$PLUGIN_FILE" "/boot/config/plugins/$(basename "$PLUGIN_FILE")"
echo "Attempting to install plugin with $PLUGIN_FILE"
if plugin install "/boot/config/plugins/$(basename "$PLUGIN_FILE")" forced; then
  echo "plugin install succeeded"
  exit 0
else
  echo "plugin install failed"
  cp -- "$PACKAGE_TAR" "/boot/config/plugins/compose.manager/$(basename "$PACKAGE_TAR")"
  if upgradepkg --install-new "/boot/config/plugins/compose.manager/$(basename "$PACKAGE_TAR")"; then
    echo "upgradepkg succeeded"
    exit 0
  else
    echo "upgradepkg failed"
  fi
fi

exit 1