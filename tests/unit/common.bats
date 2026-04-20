#!/usr/bin/env bats
#
# Unit Tests for common.sh shared functions
#

load '../bats_setup.bash'

COMMON_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/common.sh"

# ============================================================
# composeLogger Tests
# ============================================================

@test "composeLogger logs message to syslog mock" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "test log entry" "info" "general"

    assert_logged "test log entry"
}

@test "composeLogger defaults to info level" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "default level"

    assert_logged "default level"
}

@test "composeLogger includes category prefix" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    composeLogger "category msg" "warning" "cron"

    assert_logged "message= \[cron\] category msg"
}

# ============================================================
# sanitize Tests
# ============================================================

@test "sanitize lowercases and replaces special chars with underscores" {
    # shellcheck disable=SC1090
    source "$COMMON_SCRIPT"

    run sanitize "My Stack-Name.1"

    [[ "$output" == "my_stack_name_1" ]]
}
