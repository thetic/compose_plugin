#!/bin/bash
set -e

# Default values
VERSION=""
DEV=false
SKIP_TESTS=false
COMPOSE_VERSION=""
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT_PATH="$SCRIPT_DIR/archive"
PLG_FILE="$SCRIPT_DIR/compose.manager.plg"
VERSIONS_FILE="$SCRIPT_DIR/versions.env"

# Argument parsing
while [[ $# -gt 0 ]]; do
    case $1 in
        -Version|--Version)
            VERSION="$2"; shift 2;;
        -Dev|--Dev)
            DEV=true; shift;;
        -SkipTests|--SkipTests)
            SKIP_TESTS=true; shift;;
        -ComposeVersion|--ComposeVersion)
            COMPOSE_VERSION="$2"; shift 2;;
        *)
            echo "Unknown argument: $1"; exit 1;;
    esac
done

# Run tests unless skipped
test_script="$SCRIPT_DIR/test.sh"
if [[ -f "$test_script" && "$SKIP_TESTS" = false ]]; then
    echo -e "\033[1;33mRunning tests...\033[0m"
    bash "$test_script"
fi

# Read Compose version from versions.env if not supplied
if [[ -z "$COMPOSE_VERSION" && -f "$VERSIONS_FILE" ]]; then
    while IFS= read -r line; do
        if [[ "$line" =~ ^COMPOSE_VERSION=(.+)$ ]]; then
            COMPOSE_VERSION="${BASH_REMATCH[1]}"
        fi
    done < "$VERSIONS_FILE"
fi
: "${COMPOSE_VERSION:=5.0.2}"

# Generate dev version with timestamp if requested
if [[ "$DEV" = true ]]; then
    VERSION="$(date +'%Y.%m.%d.%H%M')"
    echo -e "\033[1;36mGenerated dev version: $VERSION\033[0m"
fi

# If no version specified, read from .plg file
if [[ -z "$VERSION" && -f "$PLG_FILE" ]]; then
    VERSION=$(grep -oP 'ENTITY version\s+"\K[^"]+' "$PLG_FILE" | head -n1)
    if [[ -n "$VERSION" ]]; then
        echo -e "\033[1;36mUsing version from .plg file: $VERSION\033[0m"
    else
        echo "Could not determine version. Please specify -Version parameter."; exit 1
    fi
fi

# Set build number
if [[ "$VERSION" =~ [0-9]{4}\.[0-9]{2}\.[0-9]{2}\.(\d{4})$ ]]; then
    BUILD_NUM="${BASH_REMATCH[1]}"
else
    BUILD_NUM="$(date +'%H%M')"
fi
PACKAGE_BASENAME="compose.manager-$VERSION-noarch-$BUILD_NUM"
PACKAGE_NAME="$PACKAGE_BASENAME.txz"

# Ensure output directory exists
mkdir -p "$OUTPUT_PATH"

# Generate temporary plugin manifest
TEMP_PLG="$OUTPUT_PATH/compose.manager.plg"
sed -E \
    -e "s|^\s*<!ENTITY version \".*\"|<!ENTITY version \"$VERSION\"|" \
    -e "s|^\s*<!ENTITY packageVER \".*\"|<!ENTITY packageVER \"$VERSION\"|" \
    -e "s|^\s*<!ENTITY pkgBUILD \".*\"|<!ENTITY pkgBUILD \"$BUILD_NUM\"|" \
    -e "s|^\s*<!ENTITY packageName \".*\"|<!ENTITY packageName \"$PACKAGE_BASENAME\"|" \
    -e "s|^\s*<!ENTITY packagefile \".*\"|<!ENTITY packagefile \"$PACKAGE_NAME\"|" \
    -e "s|^\s*<!ENTITY packageURL \".*\"|<!ENTITY packageURL \"file:///tmp/$PACKAGE_NAME\"|" \
    -e "s|^\s*<FILE Name=\"&pluginLOC;/&packagefile;\".*|<FILE Name='/tmp/$PACKAGE_NAME' Run='upgradepkg --install-new'>|" \
    -e "s|^\s*<URL>.*</URL>|<URL>file:///tmp/$PACKAGE_NAME</URL>|" \
    "$PLG_FILE" > "$TEMP_PLG"
echo -e "\033[1;36mGenerated temporary plugin manifest for build: $TEMP_PLG\033[0m"

# CA bundle setup
HOST_CA_CERT="${CA_CERT_PATH:-/tmp/cacert.pem}"
if [[ ! -f "$HOST_CA_CERT" ]]; then
    echo -e "\033[1;33mCA bundle not found at $HOST_CA_CERT. Downloading fresh bundle...\033[0m"
    curl -fsSL -o "$HOST_CA_CERT" 'https://curl.se/ca/cacert.pem' || { echo "Failed to download CA bundle."; exit 1; }
fi
CONTAINER_CA_CERT="/etc/ssl/certs/ca-certificates.crt"

# Build in Docker
ARCHIVE_PATH="$OUTPUT_PATH"
SOURCE_PATH="$SCRIPT_DIR/source"
echo -e "\033[1;33mRunning Docker build...\033[0m"
if ! docker run --rm --tmpfs /tmp \
    -v "$ARCHIVE_PATH:/mnt/output:rw" \
    -v "$SOURCE_PATH:/mnt/source:ro" \
    -v "$HOST_CA_CERT:$CONTAINER_CA_CERT:ro" \
    -e TZ=America/New_York \
    -e COMPOSE_VERSION="$COMPOSE_VERSION" \
    -e OUTPUT_FOLDER=/mnt/output \
    -e PKG_VERSION="$VERSION" \
    -e PKG_BUILD="$BUILD_NUM" \
    -e CA_CERT="$CONTAINER_CA_CERT" \
    vbatts/slackware:latest \
    /mnt/source/pkg_build.sh; then
    echo "Docker build failed."; exit 1
fi

PACKAGE_PATH="$OUTPUT_PATH/$PACKAGE_NAME"
if [[ -f "$PACKAGE_PATH" ]]; then
    MD5=$(md5sum "$PACKAGE_PATH" | awk '{print $1}')
    echo -e "\n\033[1;32mBuild successful!\033[0m"
    echo -e "  \033[1;36mPackage: $PACKAGE_PATH\033[0m"
    echo -e "  \033[1;36mMD5: $MD5\033[0m"
    # Update packageMD5 in the temporary plugin manifest
    sed -i -E \
        -e "s|^\s*<!ENTITY packageMD5 \".*\"|<!ENTITY packageMD5 \"$MD5\"|" \
        -e "s|^\s*<MD5>.*</MD5>|<MD5>$MD5</MD5>|" \
        "$TEMP_PLG"
fi
