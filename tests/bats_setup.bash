#!/usr/bin/env bash
#
# Compose Manager - BATS Test Setup
#
# Project-specific helpers layered on top of the plugin-tests framework.
# Source this file instead of loading the framework setup directly.
#

# Load the generic plugin-tests framework first
BATS_SETUP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
load "${BATS_SETUP_DIR}/framework/bats/setup"

# ============================================================
# Compose Manager Helpers (override / extend framework defaults)
# ============================================================

# Create a test stack directory with a compose file using Docker Compose
# spec priority naming (compose.yaml).
# Usage: create_test_stack [name]
create_test_stack() {
    local name="${1:-teststack}"
    local stack_dir="$TEST_TEMP_DIR/$name"

    mkdir -p "$stack_dir"
    create_test_compose_file "$stack_dir/compose.yaml" > /dev/null

    echo "$stack_dir"
}
