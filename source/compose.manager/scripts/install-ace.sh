#!/bin/bash
# Install Ace editor from cached archive.
# Args: <ace_zip_path> <emhttp_plugin_dir> <ace_version>
ACE_ZIP_PATH="$1"
EMHTTP_PLUGIN_DIR="$2"
ACE_VER="$3"
ACE_PLUGIN_DIR="$EMHTTP_PLUGIN_DIR/javascript/ace"

if [ -z "$ACE_ZIP_PATH" ] || [ -z "$EMHTTP_PLUGIN_DIR" ] || [ -z "$ACE_VER" ]; then
    echo "ERROR: Missing required args. Usage: $0 <ace_zip_path> <emhttp_plugin_dir> <ace_version>"
    exit 1
fi

mkdir -p "$ACE_PLUGIN_DIR"
TMPDIR_ACE="/tmp/ace-install-$$"
mkdir -p "$TMPDIR_ACE"

fail_ace_install() {
    echo "ERROR: $1"
    echo "ERROR: Ace is required for the YAML/env editor. Failing install."
    rm -rf "$TMPDIR_ACE"
    rm -f "$ACE_ZIP_PATH"
    exit 1
}

if [ -f "$ACE_ZIP_PATH" ]; then
    if unzip -q "$ACE_ZIP_PATH" \
        "ace-builds-$ACE_VER/src-min-noconflict/ace.js" \
        "ace-builds-$ACE_VER/src-min-noconflict/mode-yaml.js" \
        "ace-builds-$ACE_VER/src-min-noconflict/mode-sh.js" \
        "ace-builds-$ACE_VER/src-min-noconflict/theme-tomorrow.js" \
        "ace-builds-$ACE_VER/src-min-noconflict/theme-tomorrow_night.js" \
        -d "$TMPDIR_ACE/extracted"; then
        if cp "$TMPDIR_ACE/extracted/ace-builds-$ACE_VER/src-min-noconflict/"*.js "$ACE_PLUGIN_DIR/"; then
            if [ -f "$ACE_PLUGIN_DIR/ace.js" ]; then
                echo "Ace editor v$ACE_VER installed to $ACE_PLUGIN_DIR"
            else
                fail_ace_install "Ace editor files not found in $ACE_PLUGIN_DIR after install. Editor will be unavailable."
            fi
        else
            fail_ace_install "Failed to copy Ace editor files into $ACE_PLUGIN_DIR. Editor will be unavailable."
        fi
    else
        fail_ace_install "Failed to extract Ace editor archive from $ACE_ZIP_PATH. Editor will be unavailable."
    fi
else
    fail_ace_install "Cached Ace archive not found at $ACE_ZIP_PATH. Editor will be unavailable."
fi
rm -rf "$TMPDIR_ACE"
rm -f "$ACE_ZIP_PATH"