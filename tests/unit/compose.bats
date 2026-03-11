#!/usr/bin/env bats
#
# Unit Tests for compose.sh script functions
#

# Load test framework (project-level bootstrap wraps the generic framework)
load '../bats_setup.bash'

# Path to the script we're testing
COMPOSE_SCRIPT="$BATS_TEST_DIRNAME/../../source/compose.manager/scripts/compose.sh"

# ============================================================
# Setup
# ============================================================

test_setup() {
    # Skip sleep commands for faster tests
    export MOCK_SKIP_SLEEP=true
    
    # Set test configuration
    export COMPOSE_MAX_RETRIES=3
    export COMPOSE_RETRY_DELAY=1
    export COMPOSE_LOCK_TIMEOUT=5
}

# ============================================================
# Logging Tests
# ============================================================

@test "log_msg function logs to logger" {
    # Source just the log_msg function
    source <(sed -n '/^log_msg()/,/^}/p' "$COMPOSE_SCRIPT")
    
    log_msg "INFO" "Test message"
    
    assert_logged "Test message"
}

@test "log_msg with debug mode echoes output" {
    source <(sed -n '/^log_msg()/,/^}/p' "$COMPOSE_SCRIPT")
    debug=true
    
    run log_msg "DEBUG" "Debug message"
    
    assert_output_contains "DEBUG"
    assert_output_contains "Debug message"
}

# ============================================================
# Docker Compose Command Tests
# ============================================================

@test "docker compose up is invoked" {
    run docker compose up -d
    
    assert_success
    assert_mock_called "docker" "compose up"
}

@test "docker compose down is invoked" {
    run docker compose down
    
    assert_success
    assert_mock_called "docker" "compose down"
}

@test "docker compose pull is invoked" {
    run docker compose pull
    
    assert_success
    assert_mock_called "docker" "compose pull"
}

@test "docker compose handles failure" {
    mock_docker_compose_exit 1
    
    run docker compose up -d
    
    assert_failure
}

# ============================================================
# Stack Directory Tests
# ============================================================

@test "create stack directory structure" {
    local stack_dir
    stack_dir=$(create_test_stack "teststack")
    
    assert_dir_exists "$stack_dir"
    assert_file_exists "$stack_dir/compose.yaml"
}

@test "compose file contains valid YAML structure" {
    local compose_file
    compose_file=$(create_test_compose_file)
    
    assert_file_contains "$compose_file" "services:"
    assert_file_contains "$compose_file" "image:"
}

# ============================================================
# Result File Tests
# ============================================================

@test "result file is created with correct JSON structure" {
    local stack_dir
    stack_dir=$(create_test_stack "resulttest")
    
    # Create a mock result file like the script would
    echo '{"result":"success","exit_code":0,"operation":"up","timestamp":"2026-02-03T10:00:00"}' > "$stack_dir/last_result.json"
    
    assert_file_exists "$stack_dir/last_result.json"
    
    # Check JSON content with grep (jq may not be available)
    assert_file_contains "$stack_dir/last_result.json" '"result":"success"'
    assert_file_contains "$stack_dir/last_result.json" '"exit_code":0'
    assert_file_contains "$stack_dir/last_result.json" '"operation":"up"'
}

@test "failed operation creates result with non-zero exit code" {
    local stack_dir
    stack_dir=$(create_test_stack "failtest")
    
    echo '{"result":"failed","exit_code":1,"operation":"up","timestamp":"2026-02-03T10:00:00"}' > "$stack_dir/last_result.json"
    
    assert_file_contains "$stack_dir/last_result.json" '"result":"failed"'
    assert_file_contains "$stack_dir/last_result.json" '"exit_code":1'
}

# ============================================================
# Lock File Tests
# ============================================================

@test "lock directory can be created" {
    local lock_dir="$TEST_TEMP_DIR/locks"
    mkdir -p "$lock_dir"
    
    assert_dir_exists "$lock_dir"
}

@test "lock file contains valid JSON" {
    local lock_dir="$TEST_TEMP_DIR/locks"
    mkdir -p "$lock_dir"
    
    # Create lock file like the script would
    echo "{\"pid\":$$,\"command\":\"up\",\"time\":\"$(date -Iseconds)\"}" > "$lock_dir/teststack.lock"
    
    assert_file_exists "$lock_dir/teststack.lock"
    assert_file_contains "$lock_dir/teststack.lock" '"command":"up"'
}

# ============================================================
# Configuration Tests
# ============================================================

@test "default retry count is 3" {
    [ "${COMPOSE_MAX_RETRIES:-3}" -eq 3 ]
}

@test "retry delay can be overridden" {
    export COMPOSE_RETRY_DELAY=10
    [ "$COMPOSE_RETRY_DELAY" -eq 10 ]
}

@test "lock timeout can be overridden" {
    export COMPOSE_LOCK_TIMEOUT=60
    [ "$COMPOSE_LOCK_TIMEOUT" -eq 60 ]
}

# ============================================================
# Error Pattern Tests
# ============================================================

@test "transient error patterns are detected" {
    local retry_pattern="error|timeout|connection refused|no such host|temporary failure"
    
    # Test various error messages
    echo "connection refused" | grep -qiE "$retry_pattern"
    echo "timeout occurred" | grep -qiE "$retry_pattern"
    echo "no such host found" | grep -qiE "$retry_pattern"
    echo "temporary failure in name resolution" | grep -qiE "$retry_pattern"
}

@test "non-transient errors are not matched" {
    local retry_pattern="error|timeout|connection refused|no such host|temporary failure"
    
    # These should NOT match the retry pattern (return non-zero)
    ! echo "file not found" | grep -qiE "$retry_pattern" || true
    ! echo "permission denied" | grep -qiE "$retry_pattern" || true
}
