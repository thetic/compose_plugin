#!/bin/bash
[ -z "$OUTPUT_FOLDER" ] && echo "Output Folder not set" && exit 1
[ -z "$COMPOSE_VERSION" ] && echo "Compose Version not set" && exit 2
[ -z "$ACE_VERSION" ] && echo "ACE Version not set" && exit 4
[ -z "$PKG_VERSION" ] && echo "Package Version not set" && exit 5
tmpdir=/tmp/tmp.$((RANDOM * 19318203981230 + 40))
version=$PKG_VERSION

shopt -s extglob
set -euo pipefail
set +x

LOG_FILE=/tmp/build.log
: > "$LOG_FILE"

run_quiet() {
  # run the command, stream stdout/stderr to terminal and log file
  "$@" 2>&1 | tee -a "$LOG_FILE"
}

# Note: run_quiet uses tee so log is live and stays in /tmp/build.log.

echo "Installing unzip dependency..."
run_quiet wget --ca-certificate="$CA_CERT" --https-only --secure-protocol=TLSv1_2 https://slackware.uk/slackware/slackware64-14.2/slackware64/a/infozip-6.0-x86_64-3.txz
run_quiet upgradepkg --install-new infozip-6.0-x86_64-3.txz

echo "Creating temporary package structure at $tmpdir..."
run_quiet mkdir -p "$tmpdir"

echo "Copying source plugin files into temp structure..."
mkdir -p $tmpdir/usr/local/emhttp/plugins/compose.manager
run_quiet cp -RT /mnt/source/compose.manager/ $tmpdir/usr/local/emhttp/plugins/compose.manager/

echo "Entering temp directory and setting file permissions..."
cd $tmpdir || exit 1

echo "Marking plugin scripts and PHP executable..."
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/event/"
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/scripts/"
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/php/"

echo "Downloading Docker Compose CLI plugin v${COMPOSE_VERSION}..."
run_quiet wget --ca-certificate="$CA_CERT" --https-only --secure-protocol=TLSv1_2 "https://github.com/docker/compose/releases/download/v${COMPOSE_VERSION}/docker-compose-linux-x86_64"
run_quiet wget --ca-certificate="$CA_CERT" --https-only --secure-protocol=TLSv1_2 "https://github.com/docker/compose/releases/download/v${COMPOSE_VERSION}/docker-compose-linux-x86_64.sha256"
run_quiet sha256sum -c docker-compose-linux-x86_64.sha256 | grep -q OK || exit 4
run_quiet rm docker-compose-linux-x86_64.sha256

echo "Installing Docker Compose CLI plugin v${COMPOSE_VERSION}..."
run_quiet mkdir -p "$tmpdir/usr/lib/docker/cli-plugins/"
run_quiet cp docker-compose-linux-x86_64 "$tmpdir/usr/lib/docker/cli-plugins/docker-compose"
run_quiet chmod -R +x "$tmpdir/usr/lib/docker/cli-plugins/"
run_quiet rm docker-compose-linux-x86_64

echo "Installing Ace Editor v${ACE_VERSION}..."
run_quiet mkdir -p "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/"
run_quiet wget --ca-certificate="$CA_CERT" --https-only --secure-protocol=TLSv1_2 "https://github.com/ajaxorg/ace-builds/archive/refs/tags/v${ACE_VERSION}.zip"
run_quiet mkdir -p /tmp/ace

echo "Unpacking Ace Editor v${ACE_VERSION}..."
run_quiet unzip "v${ACE_VERSION}.zip" ace-builds-${ACE_VERSION}/src-min-noconflict/* -d "/tmp/ace"

echo "Copying Ace Editor files to package structure..."
run_quiet cp -RT "/tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict" "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/"
# shellcheck disable=SC2086
run_quiet cp /tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict/*yaml.js "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/" >> "$LOG_FILE" 2>&1 || :
# shellcheck disable=SC2086
run_quiet cp /tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict/*text.js "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/" >> "$LOG_FILE" 2>&1 || :
# shellcheck disable=SC2086
run_quiet cp /tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict/mode-sh.js "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/" >> "$LOG_FILE" 2>&1 || :

# The "Tomorrow" themes are used by default in the YAML editor, so we need to include those as well
# shellcheck disable=SC2086
run_quiet cp /tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict/*tomorrow.js "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/" >> "$LOG_FILE" 2>&1 || :
# shellcheck disable=SC2086
run_quiet cp /tmp/ace/ace-builds-${ACE_VERSION}/src-min-noconflict/*tomorrow_night.js "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/" >> "$LOG_FILE" 2>&1 || :

# Set execute permissions for Ace Editor files
run_quiet chmod -R +x "$tmpdir/usr/local/emhttp/plugins/compose.manager/javascript/ace/"
run_quiet rm -R /tmp/ace
run_quiet rm "v${ACE_VERSION}.zip"

echo "Creating package description (slack-desc)..."
run_quiet mkdir -p $tmpdir/install
cat > $tmpdir/install/slack-desc << 'EOF'
compose.manager: Compose Manager Plus - Docker Compose management for unRAID
compose.manager:
compose.manager: A plugin for managing Docker Compose stacks on unRAID.
compose.manager: Provides a web UI to create, manage, and monitor your
compose.manager: compose stacks directly from the unRAID dashboard.
compose.manager:
compose.manager: Features: Docker Compose CLI, web-based stack management,
compose.manager: autostart support, environment file support, profiles,
compose.manager: built-in YAML editor, and Docker UI integration patches.
compose.manager:
compose.manager: https://github.com/mstrhakr/compose_plugin
EOF

# Build the package
run_quiet makepkg -l y -c y "$OUTPUT_FOLDER/compose.manager-package-${version}.txz"

# Copy build log into output folder for debugging archives
if [ -d "$OUTPUT_FOLDER" ]; then
  run_quiet cp "$LOG_FILE" "$OUTPUT_FOLDER/build.log" 2>/dev/null || true
fi

# Change to root
cd /

# Calculate the MD5 checksum of the package
MD5=$(md5sum "$OUTPUT_FOLDER/compose.manager-package-${version}.txz")

# Write release info to a file in the output folder
{
  echo "MD5: $MD5"
  echo "Compose v${COMPOSE_VERSION}"
  echo "Ace v${ACE_VERSION}"
  echo ""
  echo "MD5: $(echo "$MD5" | head -n1 | awk '{print $1;}')"
} >> "$OUTPUT_FOLDER/release_info"

echo "Build log preserved at $OUTPUT_FOLDER/build.log"
